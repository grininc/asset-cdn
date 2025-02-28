<?php

namespace Arubacao\AssetCdn\Commands;

use Arubacao\AssetCdn\Finder;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\File;
use Symfony\Component\Finder\SplFileInfo;

class SyncCommand extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'asset-cdn:sync {--version-path=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronizes assets to CDN';

    /**
     * @var string
     */
    private $filesystem;

    /**
     * @var FilesystemManager
     */
    private $filesystemManager;

    /**
     * Execute the console command.
     *
     * @param Finder $finder
     * @param FilesystemManager $filesystemManager
     * @param Repository $config
     *
     * @return void
     */
    public function handle(Finder $finder, FilesystemManager $filesystemManager, Repository $config)
    {
        $this->filesystem = $config->get('asset-cdn.filesystem.disk');
        $this->filesystemManager = $filesystemManager;
        $filesOnCdn = $this->filesystemManager
            ->disk($this->filesystem)
            ->allFiles($this->version());

        $localFiles = $finder->getFiles();



        $filesToDelete = $this->filesToDelete($filesOnCdn, $localFiles, $this->version());
        $filesToSync = $this->filesToSync($filesOnCdn, $localFiles, $this->version());



        foreach ($filesToSync as $file) {


            $fileRelativePath = $this->isUsingVersion() ?
                $finder->versionRelativePath($file->getRelativePath(), $this->version()) :
                $file->getRelativePath();


            $bool = $this->filesystemManager
                ->disk($this->filesystem)
                ->putFileAs(
                    $fileRelativePath,
                    new File($file->getPathname()),
                    $file->getFilename(),
                    $config->get('asset-cdn.filesystem.options')
                );



            if (! $bool) {
                $this->error("Problem uploading: {$fileRelativePath}/" . $file->getFilename());
            } else {
                $this->info("Successfully uploaded: {$fileRelativePath}/" . $file->getFilename());
            }
        }

        if ($this->filesystemManager
            ->disk($this->filesystem)
            ->delete($filesToDelete)) {
            foreach ($filesToDelete as $file) {
                $this->info("Successfully deleted: {$file}");
            }
        }
    }

    /**
     * @param string[] $filesOnCdn
     * @param SplFileInfo[] $localFiles
     * @return SplFileInfo[]
     */
    private function filesToSync(array $filesOnCdn, array $localFiles, string $versionedPath = null): array
    {
        $array = array_filter($localFiles, function (SplFileInfo $localFile) use ($filesOnCdn, $versionedPath) {
            $localFilePathname = $localFile->getRelativePathname();

            if($versionedPath) {
                $localFilePathname = "$versionedPath/$localFilePathname";
            }


            if (! in_array($localFilePathname, $filesOnCdn)) {
                return true;
            }

            $filesizeOfCdn = $this->filesystemManager
                ->disk($this->filesystem)
                ->size($localFilePathname);
            
            if ($filesizeOfCdn != $localFile->getSize()) {
                return true;
            }

            $md5OfCdn = md5(
                $this->filesystemManager
                    ->disk($this->filesystem)
                    ->get($localFilePathname)
            );

            $md5OfLocal = md5_file($localFile->getRealPath());

            if ($md5OfLocal != $md5OfCdn) {
                return true;
            }

            return false;
        });

        return array_values($array);
    }

    /**
     * @param string[] $filesOnCdn
     * @param SplFileInfo[] $localFiles
     * @return string[]
     */
    private function filesToDelete(array $filesOnCdn, array $localFiles, string $versioned_path = null): array
    {
        $localFiles = $this->mapToPathname($localFiles);

        $array = array_filter($filesOnCdn, function (string $fileOnCdn) use ($localFiles, $versioned_path) {
            if($versioned_path) {
                $localFiles  = array_map(function (string $file) use ($versioned_path)  {
                    return "$versioned_path/$file";
                }, $localFiles);
            }

            return ! in_array($fileOnCdn, $localFiles);
        });

        return array_values($array);
    }
}
