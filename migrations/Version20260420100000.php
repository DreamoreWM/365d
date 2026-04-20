<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260420100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add codes_ocr JSON column to type_prestation for multi-code OCR detection';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE type_prestation ADD codes_ocr JSON DEFAULT NULL COMMENT '(DC2Type:json)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE type_prestation DROP COLUMN codes_ocr');
    }
}
