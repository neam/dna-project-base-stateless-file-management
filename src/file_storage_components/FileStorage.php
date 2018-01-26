<?php

namespace neam\stateless_file_management\file_storage_components;

use neam\stateless_file_management\DownloadRemoteFile404Exception;
use Exception;

interface FileStorage
{

    public function absoluteUrl();

    /** Returns the (possibly remotely located) binary data */

    /**
     * @return mixed
     * @throws DownloadRemoteFile404Exception
     * @throws Exception
     */
    public function fileContents();

    public function deliverFileContentsAsHttpResponse();

}
