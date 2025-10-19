<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande pour réinitialiser la base et charger les fixtures.
 * 
 * Usage :
 * - php bin/console app:load-fixtures
 * - php bin/console app:load-fixtures --no-interaction (sans confirmation)
 */
#[AsCommand(
    name: 'app:load-fixtures',
    description: 'Réinitialise la base de données et charge les fixtures',
)]
class LoadFixturesCommand extends Command
{
    public function __construct(
        private readonly Connection $connection
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'no-interaction',
                'n',
                InputOption::VALUE_NONE,
                'Ne pas demander de confirmation'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('🔄 Réinitialisation de la base de données + Fixtures');

        // Confirmation
        if (!$input->getOption('no-interaction')) {
            $io->warning('⚠️  Cette action va SUPPRIMER TOUTES LES DONNÉES de la base !');
            if (!$io->confirm('Voulez-vous continuer ?', false)) {
                $io->error('Opération annulée.');
                return Command::FAILURE;
            }
        }

        try {
            // Étape 1 : Drop database
            $io->section('1/6 Suppression de la base de données...');
            $this->runCommand('doctrine:database:drop', ['--force' => true, '--if-exists' => true], $output);
            $io->success('Base de données supprimée');

            // Étape 2 : Create database
            $io->section('2/6 Création de la base de données...');
            $this->runCommand('doctrine:database:create', [], $output);
            $io->success('Base de données créée');

            // Étape 3 : Migrations
            $io->section('3/6 Exécution des migrations...');
            $this->runCommand('doctrine:migrations:migrate', ['--no-interaction' => true], $output);
            $io->success('Migrations exécutées');

            // Étape 4 : Validation du schéma
            $io->section('4/6 Validation du schéma Doctrine...');
            $this->runCommand('doctrine:schema:validate', [], $output);
            $io->success('Schéma valide');

            // Étape 5 : Load fixtures
            $io->section('5/6 Chargement des fixtures...');
            $this->runCommand('doctrine:fixtures:load', ['--no-interaction' => true], $output);
            $io->success('Fixtures chargées');

            // Étape 6 : Statistiques
            $io->section('6/6 Statistiques de la base');
            $this->displayStats($io);

            // Affichage final
            $io->success('✅ Base de données réinitialisée avec succès !');
            $this->displayTestAccounts($io);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Erreur : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Exécute une commande Symfony.
     */
    private function runCommand(string $command, array $arguments, OutputInterface $output): void
    {
        $application = $this->getApplication();
        if (!$application) {
            throw new \RuntimeException('Application not found');
        }

        $command = $application->find($command);
        $input = new ArrayInput(array_merge(['command' => $command->getName()], $arguments));
        $input->setInteractive(false);

        $returnCode = $command->run($input, $output);

        if ($returnCode !== Command::SUCCESS) {
            throw new \RuntimeException("Command {$command->getName()} failed");
        }
    }

    /**
     * Affiche les statistiques de la base.
     */
    private function displayStats(SymfonyStyle $io): void
    {
        $sitesCount = $this->connection->fetchOne('SELECT COUNT(*) FROM sites');
        $usersCount = $this->connection->fetchOne('SELECT COUNT(*) FROM users');

        $io->table(
            ['Entité', 'Nombre'],
            [
                ['Sites', $sitesCount],
                ['Users', $usersCount],
            ]
        );

        // Afficher les sites
        $sites = $this->connection->fetchAllAssociative('SELECT code, name, domain, status FROM sites');

        if (!empty($sites)) {
            $io->section('🏪 Sites créés');
            $io->table(
                ['Code', 'Nom', 'Domaine', 'Statut'],
                array_map(fn($site) => [
                    $site['code'],
                    $site['name'],
                    $site['domain'],
                    $site['status']
                ], $sites)
            );
        }
    }

    /**
     * Affiche les comptes de test.
     */
    private function displayTestAccounts(SymfonyStyle $io): void
    {
        $io->section('👤 Comptes de test');

        $accounts = [
            ['Email', 'Mot de passe', 'Rôle'],
            ['superadmin@boutique-bio.fr', 'SuperAdmin123!', 'SUPER_ADMIN'],
            ['admin@boutique-bio.fr', 'Admin123!', 'ADMIN (FR)'],
            ['admin@boutique-bio.be', 'Admin123!', 'ADMIN (BE)'],
            ['moderateur@boutique-bio.fr', 'Moderator123!', 'MODERATOR'],
            ['[20+ clients standards]', 'Password123!', 'USER'],
        ];

        $io->table($accounts[0], array_slice($accounts, 1));

        $io->note([
            'Utilisez ces comptes pour tester l\'authentification JWT',
            'Exemple : curl -X POST http://localhost:8000/api/auth/login -H "Content-Type: application/json" -d \'{"email":"admin@boutique-bio.fr","password":"Admin123!"}\'',
        ]);
    }
}
