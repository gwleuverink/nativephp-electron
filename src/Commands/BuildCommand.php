<?php

namespace Native\Electron\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Native\Electron\Concerns\LocatesPhpBinary;
use Native\Electron\Facades\Updater;
use Native\Electron\Traits\CleansEnvFile;
use Native\Electron\Traits\CopiesToBuildDirectory;
use Native\Electron\Traits\InstallsAppIcon;
use Native\Electron\Traits\OsAndArch;
use Native\Electron\Traits\PrunesVendorDirectory;
use Native\Electron\Traits\SetsAppName;

use function Laravel\Prompts\intro;

class BuildCommand extends Command
{
    use CleansEnvFile;
    use CopiesToBuildDirectory;
    use InstallsAppIcon;
    use LocatesPhpBinary;
    use OsAndArch;
    use PrunesVendorDirectory;
    use SetsAppName;

    protected $signature = 'native:build
        {os? : The operating system to build for (all, linux, mac, win)}
        {arch? : The Processor Architecture to build for (x64, x86, arm64)}
        {--publish : to publish the app}';

    protected $availableOs = ['win', 'linux', 'mac', 'all'];

    protected function buildPath(): string
    {
        return __DIR__.'/../../resources/js/resources/app';
    }

    public function handle(): void
    {
        $this->setAppName(slugify: true);

        $os = $this->selectOs($this->argument('os'));

        $buildCommand = 'build';
        if ($os != 'all') {
            $arch = $this->selectArchitectureForOs($os, $this->argument('arch'));

            $os .= $arch != 'all' ? "-{$arch}" : '';

            // Should we publish?
            if ($publish = $this->option('publish')) {
                $buildCommand = 'publish';
            }
        }

        $this->newLine();
        intro('Updating Electron dependencies...');
        Process::path(__DIR__.'/../../resources/js/')
            ->env($this->getEnvironmentVariables())
            ->forever()
            ->run('npm ci', function (string $type, string $output) {
                echo $output;
            });

        $this->newLine();
        $this->copyToBuildDirectory();

        $this->newLine();
        $this->cleanEnvFile();

        $this->newLine();
        $this->installIcon();

        $this->newLine();
        $this->pruneVendorDirectory();

        $this->newLine();
        intro((($publish ?? false) ? 'Publishing' : 'Building')." for {$os}");
        Process::path(__DIR__.'/../../resources/js/')
            ->env($this->getEnvironmentVariables())
            ->forever()
            ->tty(PHP_OS_FAMILY != 'Windows' && ! $this->option('no-interaction'))
            ->run("npm run {$buildCommand}:{$os}", function (string $type, string $output) {
                echo $output;
            });
    }

    protected function getEnvironmentVariables(): array
    {
        return array_merge(
            [
                'APP_PATH' => base_path(),
                'APP_URL' => config('app.url'),
                'NATIVEPHP_BUILDING' => true,
                'NATIVEPHP_PHP_BINARY_VERSION' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
                'NATIVEPHP_PHP_BINARY_PATH' => base_path($this->phpBinaryPath()),
                'NATIVEPHP_CERTIFICATE_FILE_PATH' => base_path($this->binaryPackageDirectory().'cacert.pem'),
                'NATIVEPHP_APP_NAME' => config('app.name'),
                'NATIVEPHP_APP_ID' => config('nativephp.app_id'),
                'NATIVEPHP_APP_VERSION' => config('nativephp.version'),
                'NATIVEPHP_APP_FILENAME' => Str::slug(config('app.name')),
                'NATIVEPHP_APP_AUTHOR' => config('nativephp.author'),
                'NATIVEPHP_UPDATER_CONFIG' => json_encode(Updater::builderOptions()),
                'NATIVEPHP_DEEPLINK_SCHEME' => config('nativephp.deeplink_scheme'),
            ],
            Updater::environmentVariables(),
        );
    }
}
