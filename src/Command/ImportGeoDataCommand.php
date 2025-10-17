<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\City;
use App\Entity\Country;
use App\Repository\CountryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Intl\Countries;

#[AsCommand(
    name: 'app:import-geodata',
    description: 'Importe les pays et villes depuis les fichiers GeoNames'
)]
class ImportGeoDataCommand extends Command
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CountryRepository $countryRepository,
        private readonly string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'skip-countries',
                null,
                InputOption::VALUE_NONE,
                'Ne pas importer les pays (si déjà importés)'
            )
            ->addOption(
                'skip-cities',
                null,
                InputOption::VALUE_NONE,
                'Ne pas importer les villes'
            )
            ->addOption(
                'clear',
                null,
                InputOption::VALUE_NONE,
                'Supprimer les données existantes avant import'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Import des données GeoNames');

        // Vérifier les fichiers
        $countryFile = $this->projectDir . '/var/data/countryInfo.txt';
        $cityFile = $this->projectDir . '/var/data/cities15000.txt';

        if (!$input->getOption('skip-countries') && !file_exists($countryFile)) {
            $io->error("Fichier countryInfo.txt non trouvé : $countryFile");
            $io->note('Téléchargez-le depuis : https://download.geonames.org/export/dump/countryInfo.txt');
            return Command::FAILURE;
        }

        if (!$input->getOption('skip-cities') && !file_exists($cityFile)) {
            $io->error("Fichier cities15000.txt non trouvé : $cityFile");
            $io->note('Téléchargez-le depuis : https://download.geonames.org/export/dump/cities15000.zip');
            return Command::FAILURE;
        }

        // Nettoyage si demandé
        if ($input->getOption('clear')) {
            if ($io->confirm('⚠️  Voulez-vous vraiment supprimer toutes les données géographiques existantes ?', false)) {
                $this->clearData($io);
            }
        }

        $startTime = microtime(true);

        // Import des pays
        if (!$input->getOption('skip-countries')) {
            $this->importCountries($io, $countryFile);
        } else {
            $io->info('Import des pays ignoré (--skip-countries)');
        }

        // Import des villes
        if (!$input->getOption('skip-cities')) {
            $this->importCities($io, $cityFile);
        } else {
            $io->info('Import des villes ignoré (--skip-cities)');
        }

        $duration = round(microtime(true) - $startTime, 2);

        $io->success("Import terminé en {$duration}s !");

        // Statistiques finales
        $this->showStats($io);

        return Command::SUCCESS;
    }

    private function clearData(SymfonyStyle $io): void
    {
        $io->section('Suppression des données existantes...');

        $cityCount = $this->entityManager->createQuery('DELETE FROM App\Entity\City')->execute();
        $countryCount = $this->entityManager->createQuery('DELETE FROM App\Entity\Country')->execute();

        $io->text("✓ $cityCount villes supprimées");
        $io->text("✓ $countryCount pays supprimés");
    }

    private function importCountries(SymfonyStyle $io, string $filePath): void
    {
        $io->section('Import des pays');

        $file = fopen($filePath, 'r');
        $count = 0;

        $progressBar = new ProgressBar($output = $io, 250);
        $progressBar->start();

        while (($line = fgets($file)) !== false) {
            // Ignorer les commentaires
            if (str_starts_with($line, '#') || trim($line) === '') {
                continue;
            }

            $data = explode("\t", $line);

            if (count($data) < 18) {
                continue;
            }

            $code = trim($data[0]);

            // Vérifier si existe déjà
            if ($this->countryRepository->existsByCode($code)) {
                continue;
            }

            // Obtenir le nom français (avec fallback)
            $nameFr = $this->getFrenchCountryName($code, trim($data[4]));

            $country = new Country();
            $country->setCode($code)
                ->setIso3(trim($data[1]))
                ->setName(trim($data[4])) // Nom anglais
                ->setNameFr($nameFr)
                ->setContinent(trim($data[8]))
                ->setCapital(trim($data[5]))
                ->setCurrencyCode(trim($data[10]))
                ->setLanguages(trim($data[15]))
                ->setPhoneCode(trim($data[12]));

            $this->entityManager->persist($country);
            $count++;

            if ($count % self::BATCH_SIZE === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                $progressBar->advance(self::BATCH_SIZE);
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
        fclose($file);

        $progressBar->finish();
        $io->newLine(2);
        $io->success("✓ $count pays importés");
    }

    private function importCities(SymfonyStyle $io, string $filePath): void
    {
        $io->section('Import des villes (cela peut prendre quelques minutes)');

        // Compter les lignes pour la progress bar
        $io->text('Comptage des villes...');
        $totalLines = 0;
        $file = fopen($filePath, 'r');
        while (fgets($file) !== false) {
            $totalLines++;
        }
        fclose($file);

        $io->text("$totalLines villes à importer");

        $file = fopen($filePath, 'r');
        $count = 0;
        $skipped = 0;

        $progressBar = new ProgressBar($io, $totalLines);
        $progressBar->start();

        while (($line = fgets($file)) !== false) {
            $progressBar->advance();

            $data = explode("\t", $line);

            if (count($data) < 19) {
                $skipped++;
                continue;
            }

            $countryCode = trim($data[8]);
            $country = $this->countryRepository->findByCode($countryCode);

            if (!$country) {
                $skipped++;
                continue;
            }

            $city = new City();
            $city->setGeonameId((int) trim($data[0]))
                ->setName(trim($data[1]))
                ->setAlternateName($this->extractFrenchName(trim($data[3])))
                ->setCountry($country)
                ->setLatitude(trim($data[4]))
                ->setLongitude(trim($data[5]))
                ->setAdmin1Code(trim($data[10]))
                ->setAdmin1Name(trim($data[10])) // Simplification, devrait être mappé
                ->setPopulation((int) trim($data[14]))
                ->setTimezone(trim($data[17]));

            $this->entityManager->persist($city);
            $count++;

            if ($count % self::BATCH_SIZE === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                // Recharger les pays en cache
                $country = $this->countryRepository->findByCode($countryCode);
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
        fclose($file);

        $progressBar->finish();
        $io->newLine(2);
        $io->success("✓ $count villes importées ($skipped ignorées)");
    }

    /**
     * Extrait le nom français depuis les noms alternatifs
     * Format: "Nom1,Nom2,Nom français,Nom4"
     */
    private function extractFrenchName(string $alternateNames): ?string
    {
        if (empty($alternateNames)) {
            return null;
        }

        $names = explode(',', $alternateNames);

        // Chercher un nom qui ressemble au français (heuristique simple)
        foreach ($names as $name) {
            $name = trim($name);
            // Si contient des accents français communs
            if (preg_match('/[àâäéèêëïîôùûüÿçÀÂÄÉÈÊËÏÎÔÙÛÜŸÇ]/', $name)) {
                return $name;
            }
        }

        // Sinon retourner le premier nom alternatif
        return trim($names[0]) ?: null;
    }

    /**
     * Obtient le nom français d'un pays avec gestion des exceptions
     */
    private function getFrenchCountryName(string $code, string $fallbackName): string
    {
        // Codes pays spéciaux non reconnus par Symfony Intl
        $specialCases = [
            'XK' => 'Kosovo',
            'AN' => 'Antilles néerlandaises',
            'CS' => 'Serbie-et-Monténégro',
        ];

        if (isset($specialCases[$code])) {
            return $specialCases[$code];
        }

        try {
            return Countries::getName($code, 'fr');
        } catch (\Exception $e) {
            // En cas d'erreur, retourner le nom anglais comme fallback
            return $fallbackName;
        }
    }

    private function showStats(SymfonyStyle $io): void
    {
        $io->section('Statistiques');

        $countryCount = $this->entityManager
            ->createQuery('SELECT COUNT(c) FROM App\Entity\Country c')
            ->getSingleScalarResult();

        $cityCount = $this->entityManager
            ->createQuery('SELECT COUNT(c) FROM App\Entity\City c')
            ->getSingleScalarResult();

        $io->table(
            ['Type', 'Nombre'],
            [
                ['Pays', $countryCount],
                ['Villes', $cityCount],
            ]
        );
    }
}
