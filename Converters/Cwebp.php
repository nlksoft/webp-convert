<?php

namespace WebPConvert\Converters;

use WebPConvert\Converters\Exceptions\ConverterNotOperationalException;
use WebPConvert\Converters\Exceptions\ConverterFailedException;

class Cwebp
{
    private static $cwebpDefaultPaths = [ // System paths to look for cwebp binary
        '/usr/bin/cwebp',
        '/usr/local/bin/cwebp',
        '/usr/gnu/bin/cwebp',
        '/usr/syno/bin/cwebp'
    ];

    private static $binaryInfo = [  // OS-specific binaries included in this library
        'WinNT' => [ 'cwebp.exe', '49e9cb98db30bfa27936933e6fd94d407e0386802cb192800d9fd824f6476873'],
        'Darwin' => [ 'cwebp-mac12', 'a06a3ee436e375c89dbc1b0b2e8bd7729a55139ae072ed3f7bd2e07de0ebb379'],
        'SunOS' => [ 'cwebp-sol', '1febaffbb18e52dc2c524cda9eefd00c6db95bc388732868999c0f48deb73b4f'],
        'FreeBSD' => [ 'cwebp-fbsd', 'e5cbea11c97fadffe221fdf57c093c19af2737e4bbd2cb3cd5e908de64286573'],
        'Linux' => [ 'cwebp-linux', '916623e5e9183237c851374d969aebdb96e0edc0692ab7937b95ea67dc3b2568']
    ][PHP_OS];

    private static function updateBinaries($file, $hash, $array)
    {
        // Removes system paths if the corresponding binary does not exist
        $array = array_filter($array, function($binary) {
            return file_exists($binary);
        });

        $binaryFile = __DIR__ . '/Binaries/' . $file;

        // Throws an exception if binary file does not exist
        if (!file_exists($binaryFile)) {
            throw new ConverterNotOperationalException('Operating system is currently not supported: ' . PHP_OS);
        }

        // File exists, now generate its hash
        $binaryHash = hash_file('sha256', $binaryFile);

        // Throws an exception if binary file checksum & deposited checksum do not match
        if ($binaryHash != $hash) {
            throw new ConverterNotOperationalException('Binary checksum is invalid.');
        }

        // Appends binary file to the provided array
        $array[] = $binaryFile;

        return $array;
    }

    private static function escapeFilename($string)
    {
        // Escaping whitespaces & quotes
        $string = preg_replace('/\s/', '\\ ', $string);
        $string = filter_var($string, FILTER_SANITIZE_MAGIC_QUOTES);

        // Stripping control characters
        // see https://stackoverflow.com/questions/12769462/filter-flag-strip-low-vs-filter-flag-strip-high
        $string = filter_var($string, FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW);

        return $string;
    }

    // Checks if 'Nice' is available
    private static function hasNiceSupport()
    {
        exec("nice 2>&1", $niceOutput);

        if (is_array($niceOutput) && isset($niceOutput[0])) {
            if (preg_match('/usage/', $niceOutput[0]) || (preg_match('/^\d+$/', $niceOutput[0]))) {
                /*
                 * Nice is available - default niceness (+10)
                 * https://www.lifewire.com/uses-of-commands-nice-renice-2201087
                 * https://www.computerhope.com/unix/unice.htm
                 */

                return true;
            }

            return false;
        }
    }

    public static function convert($source, $destination, $options = array(), $prepareDestinationFolder = true)
    {
        if ($prepareDestinationFolder) {
            ConverterHelper::prepareDestinationFolderAndRunCommonValidations($source, $destination);
        }

        $defaultOptions = array(
            'quality' => 80,
            'metadata' => 'none',
            'method' => 6,
            'low-memory' => true
        );

        // For backwards compatibility
        if (defined("WEBPCONVERT_CWEBP_METHOD")) {
            if (!isset($options['method'])) {
                $options['method'] = WEBPCONVERT_CWEBP_METHOD;
            }
        }
        if (defined("WEBPCONVERT_CWEBP_LOW_MEMORY")) {
            if (!isset($options['low-memory'])) {
                $options['low-memory'] = WEBPCONVERT_CWEBP_LOW_MEMORY;
            }
        }

        $options = array_merge($defaultOptions, $options);

        if (!function_exists('exec')) {
            throw new ConverterNotOperationalException('exec() is not enabled.');
        }

        // Checks if provided binary file & its hash match with deposited version & updates cwebp binary array
        $binaries = self::updateBinaries(
            self::$binaryInfo[0],
            self::$binaryInfo[1],
            self::$cwebpDefaultPaths
        );

        /*
         * Preparing options
         */

        // Metadata (all, exif, icc, xmp or none (default))
        // Comma-separated list of existing metadata to copy from input to output
        $metadata = '-metadata ' . $options['metadata'];

        // Image quality
        $quality = '-q ' . $options['quality'];

        // Losless PNG conversion
        $fileExtension = pathinfo($source, PATHINFO_EXTENSION);
        $lossless = (
            strtolower($fileExtension) == 'png'
            ? '-lossless'
            : ''
        );

        // Built-in method option
        $method = ' -m ' . strval($options['method']);


        // Built-in low memory option
        $lowMemory = '';
        if ($options['low-memory']) {
            $lowMemory = '-low_memory';
        }

        $optionsArray = [
            $metadata = $metadata,
            $quality = $quality,
            $lossless = $lossless,
            $method = $method,
            $lowMemory = $lowMemory,
            $input = self::escapeFilename($source),
            $output = '-o ' . self::escapeFilename($destination),
            $stderrRedirect = '2>&1'
        ];

        $options = implode(' ', $optionsArray);
        $nice = (
            self::hasNiceSupport()
            ? 'nice '
            : ''
        );

        // Try all paths
        foreach ($binaries as $index => $binary) {
            $command = $nice . $binary . ' ' . $options;
            exec($command, $output, $returnCode);

            if ($returnCode == 0) { // Everything okay!
                // cwebp sets file permissions to 664 but instead ..
                // .. $destination's parent folder's permissions should be used (except executable bits)
                $destinationParent = dirname($destination);
                $fileStatistics = stat($destinationParent);

                // Apply same permissions as parent folder but strip off the executable bits
                $permissions = $fileStatistics['mode'] & 0000666;
                chmod($destination, $permissions);

                $success = true;
                break;
            }

            $success = false;
        }

        if (!$success) {
            throw new ConverterNotOperationalException('No working binaries were found');
        }
    }
}
