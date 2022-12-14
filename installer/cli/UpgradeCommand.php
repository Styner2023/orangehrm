<?php
/**
 * OrangeHRM is a comprehensive Human Resource Management (HRM) System that captures
 * all the essential functionalities required for any enterprise.
 * Copyright (C) 2006 OrangeHRM Inc., http://www.orangehrm.com
 *
 * OrangeHRM is free software; you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation; either
 * version 2 of the License, or (at your option) any later version.
 *
 * OrangeHRM is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program;
 * if not, write to the Free Software Foundation, Inc., 51 Franklin Street, Fifth Floor,
 * Boston, MA 02110-1301, USA
 */

namespace OrangeHRM\Installer\cli;

use InvalidArgumentException;
use OrangeHRM\Authentication\Dto\UserCredential;
use OrangeHRM\Config\Config;
use OrangeHRM\Framework\Http\Request;
use OrangeHRM\Installer\Controller\Upgrader\Api\ConfigFileAPI;
use OrangeHRM\Installer\Controller\Upgrader\Api\UpgraderDataRegistrationAPI;
use OrangeHRM\Installer\Exception\SystemCheckException;
use OrangeHRM\Installer\Framework\InstallerCommand;
use OrangeHRM\Installer\Util\AppSetupUtility;
use OrangeHRM\Installer\Util\Connection;
use OrangeHRM\Installer\Util\DatabaseUserPermissionEvaluator;
use OrangeHRM\Installer\Util\Logger;
use OrangeHRM\Installer\Util\StateContainer;
use OrangeHRM\Installer\Util\SystemCheck;
use OrangeHRM\Installer\Util\UpgraderConfigUtility;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Throwable;

class UpgradeCommand extends InstallerCommand
{
    public const REQUIRED_TAG = '<comment>(required)</comment>';
    public const REQUIRED_WARNING = 'This field cannot be empty';

    /**
     * @inheritDoc
     */
    public function getCommandName(): string
    {
        $this->setAliases(['upgrade']);
        return 'upgrade:run';
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->addOption('dbHost', null, InputOption::VALUE_REQUIRED)
            ->addOption('dbPort', null, InputOption::VALUE_REQUIRED)
            ->addOption('dbName', null, InputOption::VALUE_REQUIRED)
            ->addOption('dbUser', null, InputOption::VALUE_REQUIRED)
            ->addOption('dbUserPassword', null, InputOption::VALUE_REQUIRED)
            ->addOption('currentVersion', null, InputOption::VALUE_REQUIRED)
            ->addOption('systemCheckAcceptRisk', null, InputOption::VALUE_NONE);
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->getIO()->title('Database Information');
        $this->getIO()->block('Please provide the database information of the database you are going to upgrade.');
        $this->getIO()->caution(
            "IMPORTANT: Make sure it's a copy of the database of your current OrangeHRM installation and not the original database. It's highly discouraged to use the original database for upgrading since it won't be recoverable if an error occurred during the upgrade."
        );
        $this->getIO()->warning(
            "ENCRYPTION: If you have enabled data encryption in your current version, you need to copy the file 'lib/confs/cryptokeys/key.ohrm' from your current installation to corresponding location in the new version."
        );

        if ($input->isInteractive()) {
            if (!$this->hasOption($input, 'dbHost')) {
                $dbHost = $this->getRequiredField('Database Host Name');
                $input->setOption('dbHost', $dbHost);
            }
            if (!$this->hasOption($input, 'dbPort')) {
                $dbPort = $this->getIO()->ask('Database Host Port', 3306);
                $input->setOption('dbPort', $dbPort);
            }
            if (!$this->hasOption($input, 'dbName')) {
                $dbName = $this->getRequiredField('Database Name');
                $input->setOption('dbName', $dbName);
            }
            if (!$this->hasOption($input, 'dbUser')) {
                $dbUser = $this->getRequiredField('Database Username');
                $input->setOption('dbUser', $dbUser);
            }
            if (!$this->hasOption($input, 'dbUserPassword')) {
                $dbPassword = $this->getIO()->askHidden('Database User Password <comment>(hidden)</comment>');
                $input->setOption('dbUserPassword', $dbPassword);
            }
        }
        $this->throwIfParamEmpty($input, 'dbHost');
        $this->throwIfParamEmpty($input, 'dbName');
        $this->throwIfParamEmpty($input, 'dbUser');

        $dbHost = $input->getOption('dbHost');
        $dbPort = $input->getOption('dbPort') ?? 3306;
        $dbName = $input->getOption('dbName');
        $dbUser = $input->getOption('dbUser');
        $dbPassword = $input->getOption('dbUserPassword');
        $this->drawDatabaseInfoTable($dbHost, $dbPort, $dbName, $dbUser);

        StateContainer::getInstance()->storeDbInfo($dbHost, $dbPort, new UserCredential($dbUser, $dbPassword), $dbName);

        $upgraderConfigUtility = new UpgraderConfigUtility();
        try {
            $upgraderConfigUtility->checkDatabaseConnection();
        } catch (SystemCheckException $e) {
            $this->getIO()->error($e->getMessage());
            return self::INVALID;
        }

        $systemCheck = new SystemCheck();
        $this->drawSystemCheckTable($systemCheck->getSystemCheckResults(true));
        if ($systemCheck->isInterruptContinue()) {
            $this->getIO()->error('System check failed');
            if (!$this->hasOption($input, 'systemCheckAcceptRisk') && $input->isInteractive()) {
                $input->setOption(
                    'systemCheckAcceptRisk',
                    $this->getIO()->confirm('Do you want to accept the risk and continue?', false)
                );
            }

            if ($input->getOption('systemCheckAcceptRisk') !== true) {
                return self::INVALID;
            }

            $this->getIO()->warning('Accepted the risk, so continue the upgrader.');
        }

        $this->getIO()->title('Current Version Details');
        $this->getIO()->block(
            'Select your current OrangeHRM version here. You can find the version at the bottom of the OrangeHRM login page. OrangeHRM Upgrader only supports versions listed in the dropdown. Selecting a different version would lead to an upgrade failure and a database corruption.'
        );

        $appSetupUtility = new AppSetupUtility();
        $currentVersion = $appSetupUtility->getCurrentProductVersionFromDatabase();
        $currentVersion = $currentVersion ?? $input->getOption('currentVersion');
        $versions = array_keys(AppSetupUtility::MIGRATIONS_MAP);
        array_pop($versions);
        if ($input->isInteractive() && $currentVersion == null) {
            $question = new ChoiceQuestion('Current OrangeHRM Version ' . self::REQUIRED_TAG, $versions);
            $question->setValidator(function ($value) use ($versions) {
                if (!in_array($value, $versions, true)) {
                    throw new InvalidArgumentException('Invalid version.');
                }
                return $value;
            });
            $currentVersion = $this->getIO()->askQuestion($question);
        }
        if (!in_array($currentVersion, $versions, true)) {
            throw new InvalidArgumentException(
                'Invalid `currentVersion` option. Accepted values are: '
                . implode(', ', $versions)
            );
        }
        $this->getIO()->note("Current version: $currentVersion");

        $this->getIO()->title('Upgrading OrangeHRM');
        $fromAndToVersions = "from <comment>OrangeHRM $currentVersion</comment> to <comment>OrangeHRM " . Config::PRODUCT_VERSION . '</comment>';
        $continue = $this->getIO()->confirm("Do you want to start the upgrader $fromAndToVersions?", true);
        if ($continue !== true) {
            $this->getIO()->info('Aborted');
            return self::INVALID;
        }
        if (!$input->isInteractive()) {
            $this->getIO()->info(
                "Upgrading from OrangeHRM $currentVersion to OrangeHRM " . Config::PRODUCT_VERSION . '.'
            );
        }
        $section1 = $output->section();
        $section2 = $output->section();
        $section3 = $output->section();

        $section1->writeln('* Checking database permissions');
        $section2->writeln('* Applying database changes');
        $section3->writeln("* Creating configuration files\n");

        $request = new Request();
        $request->setMethod(Request::METHOD_POST);
        (new UpgraderDataRegistrationAPI())->handle($request);

        try {
            $evaluator = new DatabaseUserPermissionEvaluator(Connection::getConnection());
            $evaluator->evalPrivilegeDatabaseUserPermission();
        } catch (Throwable $e) {
            Logger::getLogger()->error($e->getMessage());
            Logger::getLogger()->error($e->getTraceAsString());
            $this->getIO()->error(
                '`Checking database permissions` failed. For more details check the error log in /src/log/installer.log file'
            );
            return self::FAILURE;
        }
        $section1->overwrite(sprintf('<fg=green>%s</>', '* Checking database permissions ✓'));

        $migrationVersions = $appSetupUtility->getVersionsInRange($currentVersion, null, false);
        if (empty($migrationVersions)) {
            $this->getIO()->error('Invalid current version');
            return self::FAILURE;
        }
        foreach ($migrationVersions as $version) {
            Logger::getLogger()->info(json_encode(['version' => $version]));
            $appSetupUtility->runMigrationFor($version);
        }
        $section2->overwrite(sprintf('<fg=green>%s</>', '* Applying database changes ✓'));

        $request = new Request();
        $request->setMethod(Request::METHOD_POST);
        (new ConfigFileAPI())->handle($request);
        $section3->overwrite(sprintf('<fg=green>%s</>', "* Creating configuration files ✓\n"));

        return self::SUCCESS;
    }

    /**
     * @param string $label
     * @return string
     */
    private function getRequiredField(string $label): string
    {
        return $this->getIO()->ask($label . ' ' . self::REQUIRED_TAG, null, function ($answer) {
            if (strlen(trim($answer)) == 0) {
                throw new InvalidArgumentException(self::REQUIRED_WARNING);
            }
            return $answer;
        });
    }

    /**
     * @param array $systemCheckResults
     */
    private function drawSystemCheckTable(array $systemCheckResults): void
    {
        $this->getIO()->title('System Check');
        $this->getIO()->block(
            'In order for your OrangeHRM installation to function properly, please ensure that all of the system check items listed below are green. If any are red, please take the necessary steps to fix them.'
        );
        foreach ($systemCheckResults as $category) {
            $rows = [];
            foreach ($category['checks'] as $check) {
                switch ($check['value']['status']) {
                    case SystemCheck::PASSED:
                        $style = '<fg=black;bg=green>%s</>';
                        break;
                    case SystemCheck::BLOCKER:
                        $style = '<fg=white;bg=red>%s</>';
                        break;
                    case SystemCheck::ACCEPTABLE:
                        $style = '<fg=black;bg=yellow>%s</>';
                        break;
                    default:
                        $style = '<fg=default;bg=default>%s</>';
                }
                $status = sprintf($style, $check['value']['message']);
                $rows[] = [$check['label'], $status];
            }
            $this->getIO()->table([$category['category']], $rows);
        }
    }

    /**
     * @param string $dbHost
     * @param string $dbPort
     * @param string $dbName
     * @param string $dbUser
     */
    private function drawDatabaseInfoTable(string $dbHost, string $dbPort, string $dbName, string $dbUser): void
    {
        $rows = [
            ['Database Host Name', $dbHost],
            ['Database Host Port', $dbPort],
            ['Database Name', $dbName],
            ['Database Username', $dbUser],
        ];
        $this->getIO()->table([], $rows);
    }

    /**
     * @param InputInterface $input
     * @param string $name
     * @return bool
     */
    private function hasOption(InputInterface $input, string $name): bool
    {
        $value = $input->getOption($name);
        if ($value == null) {
            return false;
        }
        return strlen(trim($value)) > 0;
    }

    /**
     * @param InputInterface $input
     * @param string $name
     */
    private function throwIfParamEmpty(InputInterface $input, string $name): void
    {
        $value = $input->getOption($name);
        if ($value == null || strlen(trim($value)) == 0) {
            throw new InvalidArgumentException("Missing required `$name` option.");
        }
    }
}
