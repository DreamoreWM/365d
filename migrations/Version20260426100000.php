<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260426100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add relance system: relance table + cooldown/counter fields on bon_de_commande';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE relance (
                id INT AUTO_INCREMENT NOT NULL,
                bon_de_commande_id INT NOT NULL,
                auteur_id INT DEFAULT NULL,
                date_relance DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                note LONGTEXT DEFAULT NULL,
                PRIMARY KEY(id),
                INDEX IDX_RELANCE_BON (bon_de_commande_id),
                INDEX IDX_RELANCE_AUTEUR (auteur_id),
                CONSTRAINT FK_RELANCE_BON FOREIGN KEY (bon_de_commande_id) REFERENCES bon_de_commande (id) ON DELETE CASCADE,
                CONSTRAINT FK_RELANCE_AUTEUR FOREIGN KEY (auteur_id) REFERENCES `user` (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");

        $this->addSql("ALTER TABLE bon_de_commande
            ADD prochain_relance_possible DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            ADD nombre_relances INT NOT NULL DEFAULT 0
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE relance');
        $this->addSql('ALTER TABLE bon_de_commande DROP prochain_relance_possible, DROP nombre_relances');
    }
}
