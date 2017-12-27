<?php

namespace neam\stateless_file_management\file_storage_components;

use propel\models\File;
use propel\models\FileInstance;

trait FileStorageTrait
{

    /**
     * @var \propel\models\File
     */
    protected $file;

    /**
     * @var \propel\models\FileInstance
     */
    protected $fileInstance;

    /**
     * @return File
     */
    public function getFile(): File
    {
        return $this->file;
    }

    /**
     * @return FileInstance
     */
    public function getFileInstance(): FileInstance
    {
        return $this->fileInstance;
    }

}
