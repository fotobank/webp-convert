<?php

namespace WebPConvert;

use WebPConvert\Converters\Cwebp;

class WebPConvert
{
    private static $preferredConverters = [];
    private static $excludeDefaultBinaries = false;
    private static $allowedExtensions = ['jpg', 'jpeg', 'png'];

    // set converter options
    /* ie:
        array(
          'ewww' => array(
            'key' => 'xxx897aoefu'
          ),
          'gd' => array(
            'convert_pngs' => true
      )*/
    public static function setConverterOption($converter, $optionName, $optionValue)
    {
        /*
        The old way of setting converter options is depreciated
        It will be removed in 2.0.0.

        As we still support the functionality, we can use it here, as a quick way
        of supporting the new API */

        if (($converter == 'ewww') && ($optionName == 'key')) {
            if (!defined("WEBPCONVERT_EWW_KEY")) {
                define("WEBPCONVERT_EWW_KEY", $optionValue);
            }
        }
        if (($converter == 'gd') && ($optionName == 'convert_pngs')) {
            if (!defined("WEBPCONVERT_GD_PNG")) {
                define("WEBPCONVERT_GD_PNG", $optionValue);
            }
        }
    }

    // Defines the array of preferred converters
    public static function setConverterOrder($array, $exclude = false)
    {
        self::$preferredConverters = $array;

        if ($exclude) {
            self::$excludeDefaultBinaries = true;
        }
    }

    // Throws an exception if the provided file doesn't exist
    private static function isValidTarget($filePath)
    {
        if (!file_exists($filePath)) {
            throw new \Exception('File or directory not found: ' . $filePath);
        }

        return true;
    }

    // Throws an exception if the provided file's extension is invalid
    private static function isAllowedExtension($filePath)
    {
        $fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);
        if (!in_array(strtolower($fileExtension), self::$allowedExtensions)) {
            throw new \Exception('Unsupported file extension: ' . $fileExtension);
        }

        return true;
    }

    // Creates folder in provided path & sets correct permissions
    private static function createWritableFolder($filePath)
    {
        $folder = pathinfo($filePath, PATHINFO_DIRNAME);

        if (!file_exists($folder)) {
            // TODO: what if this is outside open basedir?
            // see http://php.net/manual/en/ini.core.php#ini.open-basedir

            // First, we have to figure out which permissions to set.
            // We want same permissions as parent folder
            // But which parent? - the parent to the first missing folder

            $parentFolders = explode('/', $folder);
            $poppedFolders = [];

            while (!(file_exists(implode('/', $parentFolders)))) {
                array_unshift($poppedFolders, array_pop($parentFolders));
            }

            // Retrieving permissions of closest existing folder
            $closestExistingFolder = implode('/', $parentFolders);
            $permissions = fileperms($closestExistingFolder) & 000777;

            // Trying to create the given folder
            if (!mkdir($folder, $permissions, true)) {
                throw new \Exception('Failed creating folder: ' . $folder);
            }

            // `mkdir` doesn't respect permissions, so we have to `chmod` each created subfolder
            foreach ($poppedFolders as $subfolder) {
                $closestExistingFolder .= '/' . $subfolder;
                // Setting directory permissions
                chmod($folder, $permissions);
            }
        }

        // Checks if there's a file in $filePath & if writing permissions are correct
        if (file_exists($filePath) && !is_writable($filePath)) {
            throw new \Exception('Cannot overwrite ' . basename($filePath) . ' - check file permissions.');
        }

        // There's either a rewritable file in $filePath or none at all.
        // If there is, simply attempt to delete it
        if (file_exists($filePath) && !unlink($filePath)) {
            throw new \Exception('Existing file cannot be removed: ' . basename($filePath));
        }

        return true;
    }

    private static function getConverters()
    {
        // Prepare building up an array of converters
        $converters = [];

        // Saves all available converters inside the `Converters` directory to an array
        $availableConverters = array_map(function ($filePath) {
            $fileName = basename($filePath, '.php');
            return strtolower($fileName);
        }, glob(__DIR__ . '/Converters/*.php'));

        // Checks if preferred converters match available converters and adds all matches to $converters array
        foreach (self::$preferredConverters as $preferredConverter) {
            if (in_array($preferredConverter, $availableConverters)) {
                $converters[] = $preferredConverter;
            }
        }

        if (self::$excludeDefaultBinaries) {
            return $converters;
        }

        // Fills $converters array with the remaining available converters, keeping the updated order of execution
        foreach ($availableConverters as $availableConverter) {
            if (in_array($availableConverter, $converters)) {
                continue;
            }
            $converters[] = $availableConverter;
        }

        return $converters;
    }

    /*
      @param (string) $source: Absolute path to image to be converted (no backslashes). Image must be jpeg or png
      @param (string) $destination: Absolute path (no backslashes)
      @param (int) $quality (optional): Quality of converted file (0-100)
      @param (bool) $stripMetadata (optional): Whether or not to strip metadata. Default is to strip. Not all converters supports this
    */

    public static function convert($source, $destination, $quality = 85, $stripMetadata = true)
    {
        $success = false;

        self::isValidTarget($source);
        self::isAllowedExtension($source);
        self::createWritableFolder($destination);

        foreach (self::getConverters() as $converter) {
            $converter = ucfirst($converter);
            $className = 'WebPConvert\\Converters\\' . $converter;

            if (!is_callable([$className, 'convert'])) {
                continue;
            }

            $conversion = call_user_func(
                [$className, 'convert'],
                $source,
                $destination,
                $quality,
                $stripMetadata
            );

            if ($conversion) {
                if (file_exists($destination)) {
                    $success = true;
                    break;
                }
            }

            $success = false;
        }


        if (!$success) {
            throw new \Exception('No operational converters are available');
            return;
        }
    }
}