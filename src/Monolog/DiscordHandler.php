<?php

namespace App\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DiscordHandler extends AbstractProcessingHandler
{
    private const COLORS = [
        'debug'     => 0x95a5a6,
        'info'      => 0x3498db,
        'notice'    => 0x2ecc71,
        'warning'   => 0xf39c12,
        'error'     => 0xe74c3c,
        'critical'  => 0x8e44ad,
        'alert'     => 0xc0392b,
        'emergency' => 0x992d22,
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $webhookUrl,
        int|string|Level $level = Level::Error,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (empty($this->webhookUrl)) {
            return;
        }

        $levelName = strtolower($record->level->name);
        $color = self::COLORS[$levelName] ?? 0xe74c3c;

        $description = substr($record->message, 0, 1500);
        $fields = [];

        $context = $record->context;

        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $e = $context['exception'];
            $fields[] = [
                'name'   => 'Exception',
                'value'  => sprintf('`%s`: %s', get_class($e), substr($e->getMessage(), 0, 500)),
                'inline' => false,
            ];
            $trace = substr($e->getTraceAsString(), 0, 900);
            $fields[] = [
                'name'   => 'Trace',
                'value'  => "```\n{$trace}\n```",
                'inline' => false,
            ];
            unset($context['exception']);
        }

        $context = array_filter($context, fn($v) => $v !== null);
        if (!empty($context)) {
            $json = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false && strlen($json) <= 900) {
                $fields[] = [
                    'name'   => 'Contexte',
                    'value'  => "```json\n{$json}\n```",
                    'inline' => false,
                ];
            }
        }

        $embed = [
            'title'       => sprintf('[%s] %s', $record->level->name, $record->channel),
            'description' => $description,
            'color'       => $color,
            'timestamp'   => $record->datetime->format('c'),
            'footer'      => ['text' => '365d · ' . ($_SERVER['APP_ENV'] ?? 'prod')],
        ];

        if (!empty($fields)) {
            $embed['fields'] = $fields;
        }

        try {
            $this->httpClient->request('POST', $this->webhookUrl, [
                'json'    => ['username' => '365d Logs', 'embeds' => [$embed]],
                'timeout' => 5,
            ]);
        } catch (\Throwable) {
            // Ne jamais laisser le logging faire planter l'app
        }
    }
}
