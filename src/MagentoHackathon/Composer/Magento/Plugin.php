<?php
/**
 *
 *
 *
 *
 */

namespace MagentoHackathon\Composer\Magento;

use Composer\Config;
use Composer\Installer;
use Composer\Script\CommandEvent;
use MagentoHackathon\Composer\Helper;
use MagentoHackathon\Composer\Magento\Event\EventManager;
use MagentoHackathon\Composer\Magento\Event\PackageDeployEvent;
use MagentoHackathon\Composer\Magento\Factory\DeploystrategyFactory;
use MagentoHackathon\Composer\Magento\Factory\EntryFactory;
use MagentoHackathon\Composer\Magento\Factory\ParserFactory;
use MagentoHackathon\Composer\Magento\Factory\PathTranslationParserFactory;
use MagentoHackathon\Composer\Magento\Installer\MagentoInstallerAbstract;
use MagentoHackathon\Composer\Magento\Installer\ModuleInstaller;
use MagentoHackathon\Composer\Magento\Patcher\Bootstrap;
use MagentoHackathon\Composer\Magento\Repository\InstalledPackageFileSystemRepository;
use MagentoHackathon\Composer\Magento\UnInstallStrategy\UnInstallStrategy;
use MagentoHackathon\Composer\Magento\Factory\InstallStrategyFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use Symfony\Component\Process\Process;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * The type of packages this plugin supports
     */
    const PACKAGE_TYPE = 'magento-module';
    
    const VENDOR_DIR_KEY = 'vendor-dir';

    const BIN_DIR_KEY = 'bin-dir';

    const THESEER_AUTOLOAD_EXEC_BIN_PATH = '/phpab';

    const THESEER_AUTOLOAD_EXEC_REL_PATH = '/theseer/autoload/composer/bin/phpab';

    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * @var ProjectConfig
     */
    protected $config;

    /**
     * @var DeployManager
     */
    protected $deployManager;

    /**
     * @var Composer
     */
    protected $composer;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var EntryFactory
     */
    protected $entryFactory;

    /**
     * init the DeployManager
     *
     * @param Composer    $composer
     * @param IOInterface $io
     */
    protected function initDeployManager(Composer $composer, IOInterface $io, EventManager $eventManager)
    {
        $this->deployManager = new DeployManager($eventManager);
        $this->deployManager->setSortPriority($this->getSortPriority($composer));

        $this->applyEvents($eventManager);
    }
    
    protected function applyEvents(EventManager $eventManager)
    {

        if ($this->config->hasAutoAppendGitignore()) {
            $gitIgnoreLocation = sprintf('%s/.gitignore', $this->config->getMagentoRootDir());
            $eventManager->listen('post-package-deploy', new GitIgnoreListener(new GitIgnore($gitIgnoreLocation)));
        }

        $io = $this->io;
        if ($this->io->isDebug()) {
            $eventManager->listen('pre-package-deploy', function(PackageDeployEvent $event) use ($io) {
                $io->write('Start magento deploy for ' . $event->getDeployEntry()->getPackageName());
            });
        }
    }

    /**
     * get Sort Priority from extra Config
     *
     * @param \Composer\Composer $composer
     *
     * @return array
     */
    private function getSortPriority(Composer $composer)
    {
        $extra = $composer->getPackage()->getExtra();

        return isset($extra[ProjectConfig::SORT_PRIORITY_KEY])
            ? $extra[ProjectConfig::SORT_PRIORITY_KEY]
            : array();
    }

    /**
     * Apply plugin modifications to composer
     *
     * @param Composer    $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->io = $io;
        $this->composer = $composer;

        $this->filesystem = new Filesystem();
        $this->config = new ProjectConfig($composer->getPackage()->getExtra(), $composer->getConfig()->all());

        $this->entryFactory = new EntryFactory(
            $this->config,
            new DeploystrategyFactory($this->config),
            new PathTranslationParserFactory(new ParserFactory($this->config), $this->config)
        );

        $this->initDeployManager($composer, $io, $this->getEventManager());

        $this->writeDebug('activate magento plugin');

        /*
        $moduleInstaller = new ModuleInstaller($io, $composer, $this->entryFactory);
        $moduleInstaller->setDeployManager($this->deployManager);

        $composer->getInstallationManager()->addInstaller($moduleInstaller);
        /**/
    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * The array keys are event names and the value can be:
     *
     * * The method name to call (priority defaults to 0)
     * * An array composed of the method name to call and the priority
     * * An array of arrays composed of the method names to call and respective
     *   priorities, or 0 if unset
     *
     * For instance:
     *
     * * array('eventName' => 'methodName')
     * * array('eventName' => array('methodName', $priority))
     * * array('eventName' => array(array('methodName1', $priority), array('methodName2'))
     *
     * @return array The event names to listen to
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::POST_INSTALL_CMD => array(
                array('onNewCodeEvent', 0),
            ),
            ScriptEvents::POST_UPDATE_CMD  => array(
                array('onNewCodeEvent', 0),
            ),
        );
    }

    /**
     * event listener is named this way, as it listens for events leading to changed code files
     *
     * @param CommandEvent $event
     */
    public function onNewCodeEvent(CommandEvent $event)
    {

        $packageTypeToMatch = static::PACKAGE_TYPE;
        $magentoModules = array_filter(
            $this->composer->getRepositoryManager()->getLocalRepository()->getPackages(),
            function (PackageInterface $package) use ($packageTypeToMatch) {
                return $package->getType() === $packageTypeToMatch;
            }
        );

        $vendorDir = rtrim($this->composer->getConfig()->get(self::VENDOR_DIR_KEY), '/');

        Helper::initMagentoRootDir(
            $this->config,
            $this->io,
            $this->filesystem,
            $vendorDir
        );

        $eventManager = new EventManager;
        $this->applyEvents($eventManager);
        $moduleManager = new ModuleManager(
            new InstalledPackageFileSystemRepository(
                $vendorDir.'/installed.json',
                new InstalledPackageDumper()
            ),
            $eventManager,
            $this->config,
            new UnInstallStrategy($this->filesystem),
            new InstallStrategyFactory($this->config, new ParserFactory($this->config))
        );

        if (in_array('--redeploy', $event->getArguments())) {
            $this->writeDebug('remove all deployed modules');
            $moduleManager->updateInstalledPackages(array());
        }
        $this->writeDebug('start magento module deploy via moduleManager');
        $moduleManager->updateInstalledPackages($magentoModules);
        $this->deployLibraries();

        if (file_exists($this->config->getMagentoRootDir() . '/app/Mage.php')) {
            $patcher = new Bootstrap($this->config->getMagentoRootDir());
            $patcher->patch();
        }
        
    }

    /**
     * deploy Libraries
     */
    protected function deployLibraries()
    {
        $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();
        $autoloadDirectories = array();

        $libraryPath = $this->config->getLibraryPath();

        if ($libraryPath === null) {
            $this->writeDebug('jump over deployLibraries as no Magento libraryPath is set');

            return;
        }

        $vendorDir = rtrim($this->composer->getConfig()->get(self::VENDOR_DIR_KEY), '/');

        $this->filesystem->removeDirectory($libraryPath);
        $this->filesystem->ensureDirectoryExists($libraryPath);

        foreach ($packages as $package) {
            /** @var PackageInterface $package */
            $packageConfig = $this->config->getLibraryConfigByPackagename($package->getName());
            if ($packageConfig === null) {
                continue;
            }
            if (!isset($packageConfig['autoload'])) {
                $packageConfig['autoload'] = array('/');
            }
            foreach ($packageConfig['autoload'] as $path) {
                $autoloadDirectories[] = $libraryPath . '/' . $package->getName() . "/" . $path;
            }
            $this->writeDebug(sprintf('Magento deployLibraries executed for %s', $package->getName()));

            $libraryTargetPath = $libraryPath . '/' . $package->getName();
            $this->filesystem->removeDirectory($libraryTargetPath);
            $this->filesystem->ensureDirectoryExists($libraryTargetPath);
            $this->copyRecursive($vendorDir . '/' . $package->getPrettyName(), $libraryTargetPath);
        }

        if (false !== ($executable = $this->getTheseerAutoloadExecutable())) {
            $this->writeDebug('Magento deployLibraries executes autoload generator');

            $params = $this->getTheseerAutoloadParams($libraryPath, $autoloadDirectories);

            $process = new Process($executable . $params);
            $process->run();
        }
    }

    /**
     * return the autoload generator binary path or false if not found
     *
     * @return bool|string
     */
    protected function getTheseerAutoloadExecutable()
    {
        $executable = $this->composer->getConfig()->get(self::BIN_DIR_KEY)
            . self::THESEER_AUTOLOAD_EXEC_BIN_PATH;

        if (!file_exists($executable)) {
            $executable = $this->composer->getConfig()->get(self::VENDOR_DIR_KEY)
                . self::THESEER_AUTOLOAD_EXEC_REL_PATH;
        }

        if (!file_exists($executable)) {
            $this->writeDebug(
                'Magento deployLibraries autoload generator not available, you should require "theseer/autoload"',
                $executable
            );

            return false;
        }

        return $executable;
    }

    /**
     * get Theseer Autoload Generator Params
     *
     * @param string $libraryPath
     * @param array  $autoloadDirectories
     *
     * @return string
     */
    protected function getTheseerAutoloadParams($libraryPath, $autoloadDirectories)
    {
        // @todo  --blacklist 'test\\\\*'
        return " -b {$libraryPath} -o {$libraryPath}/autoload.php  " . implode(' ', $autoloadDirectories);
    }

    /**
     * Copy then delete is a non-atomic version of {@link rename}.
     *
     * Some systems can't rename and also don't have proc_open,
     * which requires this solution.
     *
     * copied from \Composer\Util\Filesystem::copyThenRemove and removed the remove part
     *
     * @param string $source
     * @param string $target
     */
    protected function copyRecursive($source, $target)
    {
        $it = new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS);
        $ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::SELF_FIRST);
        $this->filesystem->ensureDirectoryExists($target);

        foreach ($ri as $file) {
            $targetPath = $target . DIRECTORY_SEPARATOR . $ri->getSubPathName();
            if ($file->isDir()) {
                $this->filesystem->ensureDirectoryExists($targetPath);
            } else {
                copy($file->getPathname(), $targetPath);
            }
        }
    }

    /**
     * print Debug Message
     *
     * @param $message
     */
    private function writeDebug($message, $varDump = null)
    {
        if ($this->io->isDebug()) {
            $this->io->write($message);

            if (!is_null($varDump)) {
                var_dump($varDump);
            }
        }
    }

    /**
     * @return EventManager
     */
    public function getEventManager()
    {
        return new EventManager;
    }

    /**
     * @param PackageInterface $package
     * @return string
     */
    public function getPackageInstallPath(PackageInterface $package)
    {
        $vendorDir = realpath(rtrim($this->composer->getConfig()->get('vendor-dir'), '/'));
        return sprintf('%s/%s', $vendorDir, $package->getPrettyName());
    }
}
