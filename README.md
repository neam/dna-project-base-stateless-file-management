DNA Project Base Stateless File Management
==========================================

Schema addition and helper traits that encapsulates [DNA Project Base](http://neamlabs.com/dna-project-base/) file-handling logic.

Instead of storing paths or uris to paths in the items of the data model, items that need to keep track of files will use a relation to a File item type, which in turn has relations to FileInstance items that specify where binary copies of the file are stored.

## Features

- Abstracts away stateless file management in a PHP application
- Stores metadata about files available remotely, so that queries of files can be done without physical access to the files
- Uses the local filesystem only as a brief, single-transaction cache, for instance downloading a large file, operating on it, and storing the results of the operation in the database / uploading a modified version of the file.
- Uses Filestack.com as a secure remote file storage for files
- Enables the use of Filestack.com's widgets and JS SDK for browser-based file uploads
- Enables the use of Filestack.com's file conversion API
- Ability to push public files to S3 buckets so that they can be made available to others 

## Background and motive

The following quote from [https://12factor.net/processes](https://12factor.net/processes) describes the motive behind this approach:

    Twelve-factor processes are stateless and share-nothing. Any data that needs to persist must be stored in a stateful backing service, typically a database.
    
    The memory space or filesystem of the process can be used as a brief, single-transaction cache. For example, downloading a large file, operating on it, and storing the results of the operation in the database. The twelve-factor app never assumes that anything cached in memory or on disk will be available on a future request or job – with many processes of each type running, chances are high that a future request will be served by a different process. Even when running only one process, a restart (triggered by code deploy, config change, or the execution environment relocating the process to a different physical location) will usually wipe out all local (e.g., memory and filesystem) state.

## Design principles

Some principles upon which these helpers are built:
 
1. Local file manipulation should be available simply by reading LOCAL_TMP_FILES_PATH . DIRECTORY_SEPARATOR . $file->getPath() as defined in getLocalAbsolutePath()
2. The path to the file is relative to the storage component's file system and should follow the format $file->getId() . DIRECTORY_SEPARATOR . $file->getFilename() - this is the file's "correct path" and ensures that multiple files with the same filename can be written to all file systems
3. Running $file->ensureCorrectLocalFile() ensures §1 and §2 (designed to run before local file manipulation, post file creation/modification time and/or as a scheduled process)
4. File instance records tell us where binary copies of the file are stored
5. File instances should (if possible) store it's binary copy using the relative path provided by $file->getPath(), so that retrieval of the file's binary contents is straightforward and eventual public url's follow the official path/name supplied by $file->getPath()

Current storage components handled by this trait:
 - local (implies that the binary is stored locally)
 - filestack (implies that the binary is stored at filestack)
 - filestack-pending (implies that the binary is pending an asynchronous task to finish, after which point the instance will be converted into a 'filestack' instance)
 - filepicker (legacy filestack name, included only to serve filepicker-stored files until all have been converted to filestack-resources)
 - public-files-s3 (implies that the binary is stored in Amazon S3 in a publicly accessible bucket)

## Installation

1. Copy file and file_instance table schemas to your schema.xml and generate the new propel models
2. Add the `\neam\stateless_file_management\FileTrait` trait to File model

    class File extends BaseFile
    {
        use \neam\stateless_file_management\FileTrait;
    }

3. Wherever your data model requires files, add foreign key relationships to the file table, see example_item_type in schema.xml for an example.

4. Set the following constants in your app

`LOCAL_TMP_FILES_PATH` - to a path where local temporary files can be written and read by the php process

`PUBLIC_FILES_S3_BUCKET` - Amazon S3 bucket where publicly shared files are to be stored

`PUBLIC_FILES_S3_REGION` - The region of the S3 bucket

`PUBLIC_FILE_UPLOADERS_ACCESS_KEY` - Amazon S3 access key for access to the above bucket

`PUBLIC_FILE_UPLOADERS_SECRET` - Amazon S3 access secret for access to the above bucket

`FILESTACK_API_KEY` - [Filestack.com](https://www.filestack.com/) API key

`FILESTACK_API_SECRET` - Used to sign URLs for temporary access to secured Filestack resources

Note: DNA Project Base uses [PHP App Config](https://github.com/neam/php-app-config) to set constants based on expected config environment variables. 

## Usage

Example of using a file locally:

    $exampleItemType = \propel\models\ExampleItemTypeQuery::create()->findPk(1);
    $inputFile = $exampleItemType->getFile();

    $inputFileName = $inputFile->getFilename();
    $mimeType = $inputFile->getMimetype();
    switch ($mimeType) {
        case 'text/plain':
        case 'text/csv':
        case 'application/vnd.ms-excel':
        case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':

            // Actually downloads the file
            $inputFilePath = $inputFile->getAbsoluteLocalPath();

            // Example of using the file contents in a PHP library that expects an absolute path to a local file
            $cellData = SpreadsheetDataFileHelper::getSpreadsheetCellData($inputFilePath);

            // ... 
            
            break;
        default:
            throw new Exception("Unsupported mimetype ('$mimeType')");
            break;
    }

Example of creating a file locally and making sure it is stored remotely:

    $exampleItemType = \propel\models\ExampleItemTypeQuery::create()->findPk(1);

TODO
