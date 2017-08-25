<?php

namespace neam\stateless_file_management;

use GuzzleHttp;
use propel\models\File;
use propel\models\FileInstance;
use ContextIOHelper;

trait ContextIoFileTrait
{

    /**
     * The URL returned is a public URL that doesn't require authentication or OAuth signatures.
     * You can redirect users to it to trigger a download from their browser.
     * That way, you don't have to download the data to your app server only to stream it back to your user.
     * The URL is valid for a 2 minute period after the time it has been generated. There is no limit to the number of downloads until the URL expires.
     * Docs: https://context.io/docs/2.0/accounts/files/content
     *
     * @param FileInstance $fileInstance
     * @return mixed
     */
    static public function contextIoPublicUrl(\propel\models\FileInstance $fileInstance)
    {
        $data = json_decode($fileInstance->getDataJson());
        $contextIoFileId = $data->contextIoAttachmentObject->file_id;
        $contextIoApiKey = $data->contextIoApiKey;
        $contextIoAccountId = $data->contextIoAccountId;
        ContextIoHelper::contextIOAllowedAccountId($contextIoAccountId);
        $contextIO = ContextIoHelper::contextIO();
        $params = [
            'file_id' => $contextIoFileId,
            'as_link' => 1
        ];
        $publicUrl = $contextIO->getFileURL($contextIoAccountId, $params);
        return $publicUrl;

    }

    static public function restApiContextIoPublicUrlForwardingEndpoint(\propel\models\File $file)
    {
        return "//" . APPVHOST . "/api/v0/file/{$file->getId()}?retrieveIMAPMailAttachment=1";
    }

    static protected function createFileInstanceWithMetadataFromContextIoAttachmentMetadata($contextIoAttachmentMetadata
    ) {

        $fileInstance = new FileInstance();
        $fileInstance->setStorageComponentRef('context-io');
        static::decorateFileInstanceWithContextIoMetadataFromContextIoAttachmentMetadata(
            $fileInstance,
            $contextIoAttachmentMetadata
        );
        $fileInstance->setUri($contextIoAttachmentMetadata->contextIoAttachmentObject->resource_url);
        return $fileInstance;

    }

    static protected function decorateFileInstanceWithContextIoMetadataFromContextIoAttachmentMetadata(
        \propel\models\FileInstance $fileInstance,
        $contextIoAttachmentMetadata
    ) {

        $fileInstance->setDataJson(json_encode($contextIoAttachmentMetadata));

    }

    static public function createFileFromContextIoAttachmentMetadata($contextIoAttachmentMetadata)
    {

        $fileInstance = static::createFileInstanceWithMetadataFromContextIoAttachmentMetadata(
            $contextIoAttachmentMetadata
        );

        $file = new File();
        static::setFileMetadataFromContextIoFileInstanceMetadata($file, $fileInstance);
        $file->setFileInstanceRelatedByContextIoFileInstanceId($fileInstance);

        return $file;

    }

    static protected function setFileMetadataFromContextIoFileInstanceMetadata(
        \propel\models\File &$file,
        \propel\models\FileInstance $fileInstance
    ) {

        $data = json_decode($fileInstance->getDataJson());

        $file->setSize($data->contextIoAttachmentObject->size);
        $file->setMimetype($data->contextIoAttachmentObject->type);
        $file->setFilename($data->contextIoAttachmentObject->file_name);
        $file->setOriginalFilename($data->contextIoAttachmentObject->file_name);

    }

}
