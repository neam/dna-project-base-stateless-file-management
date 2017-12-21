<?php

namespace neam\stateless_file_management;

/**
 * Minimal implementation of a class that represents a File record, used for internal unit tests
 */

/**
 * Interface FileInterface
 * @uses FileTrait
 * @package neam\stateless_file_management
 */
interface FileInterface
{

    use FileTrait;

    public function getMimetype();

    public function setMimetype($mimetype);

    public function getSize();

    public function setSize($size);

    public function getPath();

    public function setPath($path);

}
