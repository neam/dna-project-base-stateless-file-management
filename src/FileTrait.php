<?php

namespace neam\stateless_file_management;

use Exception;
use Operations;
use neam\stateless_file_management\file_storage_components\FileStorage;
use neam\stateless_file_management\file_storage_components\FilestackFileStorage;
use neam\stateless_file_management\file_storage_components\LocalFileStorage;
use neam\stateless_file_management\file_storage_components\PublicFilesS3FileStorage;

/**
 * Helper trait that encapsulates DNA project base file-handling logic
 *
 * Some principles:
 *  §1 Local file manipulation should be available simply by reading LOCAL_TMP_FILES_PATH . DIRECTORY_SEPARATOR . $file->getPath() as defined in getLocalAbsolutePath()
 *  §2 The path to the file is relative to the storage component's file system and should follow the format $file->getId() . DIRECTORY_SEPARATOR . $file->getFilename() - this is the file's "correct path" and ensures that multiple files with the same filename can be written to all file systems
 *  §3 Running $file->ensureCorrectLocalFile() ensures §1 and §2 (designed to run before local file manipulation, post file creation/modification time and/or as a scheduled process)
 *  §4 File instance records tell us where binary copies of the file are stored
 *  §5 File instances should (if possible) store it's binary copy using the relative path provided by $file->getPath(), so that retrieval of the file's binary contents is straightforward and eventual public url's follow the official path/name supplied by $file->getPath()
 *
 * Current storage components handled:
 *  - local (implies that the binary is stored locally)
 *  - filestack (implies that the binary is stored at filestack)
 *  - filestack-pending (implies that the binary is pending an asynchronous task to finish, after which point the instance will be converted into a 'filestack' instance)
 *  - filepicker (legacy filestack name, included only to serve filepicker-stored files until all have been converted to filestack-resources)
 *  - public-files-s3 (implies that the binary is stored in Amazon S3 in a publicly accessible bucket)
 *
 * You can easily implement app-specific storage components by creating a new FileStorage class and letting File override FOOO
 *
 * Class FileTrait
 */
trait FileTrait
{

    /**
     * @param $url
     * @param $targetFileHandle
     * @return mixed
     * @throws DownloadRemoteFile404Exception
     * @throws Exception
     */
    static public function downloadRemoteFileToStream($url, $targetFileHandle)
    {
        Operations::status(__METHOD__);
        if (empty($url)) {
            throw new Exception("Invalid url argument ('$url') to downloadRemoteFileToStream()");
        }
        if (substr($url, 0, 2) === "//") {
            $url = "http:" . $url;
        }
        if (!is_resource($targetFileHandle)) {
            throw new Exception("Invalid targetFileHandle argument to downloadRemoteFileToStream() - not a resource");
        }
        static::downloadRemoteFileUsingFopen($url, $targetFileHandle);
        Operations::status("Downloaded file from $url");
        return $targetFileHandle;
    }

    /**
     * @param $url
     * @param $targetFileHandle
     * @return mixed
     * @throws DownloadRemoteFile404Exception
     * @throws Exception
     */
    static protected function downloadRemoteFileUsingFopen($url, $targetFileHandle)
    {
        $BUFSIZ = 4095;
        $rfile = @fopen($url, 'r');
        if (!$rfile) {
            $error = error_get_last();
            if (strpos($error['message'], "HTTP/1.1 404 Not Found") !== false) {
                throw new DownloadRemoteFile404Exception($error['message']);
            }
            throw new Exception("Failed to open file handle against $url: {$error['message']}");
        }
        $lfile = $targetFileHandle;
        while (!feof($rfile)) {
            fwrite($lfile, fread($rfile, $BUFSIZ), $BUFSIZ);
        }
        fclose($rfile);
        return $lfile;
    }

    public function absoluteUrl()
    {
        $fileStorage = $this->firstAvailableFileStorage();
        return $fileStorage->absoluteUrl();
    }

    /**
     * @propel
     * @return string
     * @throws Exception
     */
    public function getCorrectPath()
    {
        /** @var \propel\models\File $this */
        $id = $this->getId();
        if (empty($id)) {
            throw new Exception("File's id not set - can't calculate the correct path");
        }
        $filename = \neam\Sanitize::filename($this->getFilename());
        return $this->getId() . DIRECTORY_SEPARATOR . trim($filename);
    }

    public function ensureCorrectPath()
    {
        if (empty($this->getId())) {
            $this->save();
        }
        if ($this->getPath() !== $this->getCorrectPath()) {
            $this->setPath($this->getCorrectPath());
        }
        return $this->getPath();
    }

    /**
     * @propel
     * @return mixed|null|\propel\models\FileInstance
     */
    public function localFileInstance()
    {
        /** @var \propel\models\File $this */
        return $this->getFileInstanceRelatedByLocalFileInstanceId() ? $this->getFileInstanceRelatedByLocalFileInstanceId() : null;
    }

    /**
     * Return the first available file storage for the current file where it is expected to find
     * a binary copy of the file. Prefer remote file storages since it is assumed to be used to deliver file contents to end-users
     * with access to cloud services and that most running instances of the application do not have a cached local copy of
     * the binary available.
     *
     * @return FileStorage
     * @throws NoAvailableFileStorageException
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function firstAvailableFileStorage(): FileStorage
    {
        try {
            return $this->firstAvailableRemoteFileStorage();
        } catch (NoAvailableFileStorageException $e) {
            if (($fileInstance = $this->getFileInstanceRelatedByLocalFileInstanceId()
                ) && !empty($fileInstance->getUri())) {
                return LocalFileStorage::create($this, $fileInstance);
            }
        }
        throw new NoAvailableFileStorageException();
    }

    /**
     * Return first best remote file storage where it is expected to find
     * a binary copy of the file when there is no local file available
     *
     * @return FilestackFileStorage|PublicFilesS3FileStorage
     * @throws NoAvailableFileStorageException
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function firstAvailableRemoteFileStorage()
    {
        /** @var \propel\models\File $this */
        if (($fileInstance = $this->getFileInstanceRelatedByFilestackFileInstanceId()
            ) && !empty($fileInstance->getUri())) {
            return FilestackFileStorage::create($this, $fileInstance);
        }
        if (($fileInstance = $this->getFileInstanceRelatedByPublicFilesS3FileInstanceId()
            ) && !empty($fileInstance->getUri())) {
            return PublicFilesS3FileStorage::create($this, $fileInstance);
        }
        throw new NoAvailableFileStorageException();
    }

    /**
     * Return the first best remote public file storage where it is expected to find
     * a public binary copy of the file
     *
     * @return PublicFilesS3FileStorage
     * @throws NoAvailableFileStorageException
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function firstAvailableRemotePublicFileStorage()
    {
        /** @var \propel\models\File $this */
        if (($fileInstance = $this->getFileInstanceRelatedByPublicFilesS3FileInstanceId()
            ) && !empty($fileInstance->getUri())) {
            return PublicFilesS3FileStorage::create($this, $fileInstance);
        }
        throw new NoAvailableFileStorageException();
    }

    /**
     * Return pending file instance (one that will be available at a later point in time)
     * @propel
     * @return string ''
     */
    public function pendingFileInstance()
    {
        /** @var \propel\models\File $this */
        return $this->getFileInstanceRelatedByFilestackPendingFileInstanceId() ? $this->getFileInstanceRelatedByFilestackPendingFileInstanceId() : null;
    }

}

class NoAvailableFileStorageException extends Exception
{

}

class DownloadRemoteFile404Exception extends Exception
{

}
