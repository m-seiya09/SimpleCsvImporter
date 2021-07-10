<?php

namespace SimpleCsvImporter\ABC;

use SimpleCsvImporter\Exceptions\CsvColumnException;
use SimpleCsvImporter\Exceptions\PropertyException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use SplFileObject;
use Throwable;

/**
* An abstract class for importing simple format csv files.
* By executing execute, the import result of csv is returned in a multidimensional array.
* To use this class, inherit it with a concrete class and
* Define the property "csvColumns", "modelColumns", "encodings", "splFileObjectFlags",
* Functions, "valueValidationRule", and "valueValidationMessage" must be defined in the concrete class.
*/
abstract class SimpleCsvImporterABC {

    /* ===== !!! Required property (define in concrete class) !!! ===== */

    /**
     * Possible character encoding for the file to be imported
     * @var array
     */
    protected $encodings = [];

    /**
     * Define columns in csv file
     * @var array
     */
    protected $csvColumns = [];

    /**
     * System side data name of csv item name (csvColumns)
     * @var array
     */
    protected $modelColumns = [];

    /**
     * Flags to set for SplFileObject
     * @var int
     */
    protected $splFileObjectFlags;

    /* ===== Property definition (overridable) ===== */

    /**
     * Define the character code after encoding
     * @var string
     */
    protected $encodeType = 'UTF-8';

    /**
     * Line number where the item name (header) of the target csv exists
     * @var integer
     */
    protected $csvColumnRowNumber = 1;

    /**
     * Number of lines allowed to read
     * (Caution) If the value is too large, memory may be exceeded.
     * @var integer
     */
    protected $maxCsvRows = 1000;

    /* ===== private property definition ===== * /
    
    /**
     * Store the extraction result
     * [status]  : Result status code
     * [invalid] : The item that caused an error in validation is stored. key is the line number and value is the array
     * [extracts]: Stores the results that were successfully extracted
     * [warning] : Store the error message in value with the line number that caused the partial failure to extract the result as the key.
     * [error]   : Stores various errors. Contains errors thrown during processing
     * @var array
     */
    private $result = [
        'status'   => 200,
        'invalid'  => [],
        'extracts' => [],
        'warning'  => [],
        'error'    => [],
    ];

    /**
     * Status code stored in [status] of "result".
     * [success]: Indicates that the results were successfully extracted without any problems.
     * [column_error]: Error when uploaded csv columns do not match property "csvColumns"
     * [partially_error]: Partial error. Although the results can be extracted as a whole, there are some parts where the extraction of the results failed.
     * [property_error]: Indicates that an error occurred in checking the required properties. The error content is stored in the [error] item of the "result" variable.
     * [problem]: Indicates that a fatal error occurred when extracting the result. The error content is stored in the [error] item of the "result" variable.
     */
    private const STATUS_CODE = [
        'success'         => 200,
        'column_error'    => 100,
        'partially_error' => 105,
        'property_error'  => 5,
        'problem'         => 0
    ];

    /* ===== !!! Be sure to implement it in the concrete class !!! ===== */

    /**
     * Define validation rules
     * @return array
     */
    abstract protected function valueValidationRule(): array;

    /**
     * Define the error message to be issued in case of validation error
     * @return array
     */
    abstract protected function valueValidationMessage(): array;

    /* ========== Execution function ========== */

    /**
     * Execute the file import process.
     * The results of the import are summarized in an array.
     * @param UploadedFile $file
     * @return array|null
     */
    public final function execute(UploadedFile $file): array
    {
        try {
            // Checking required properties
            $this->propertyValidate();

            $file = new SplFileObject($file->getRealPath());
            $this->setSplFIleObjectFlags($file);

            // Extract csv data
            $this->extract($file);

            // Judgment of final result
            if ($this->result['status'] === self::STATUS_CODE['success']) $this->setFinalResult();

        } catch(Throwable $e) {
            if ($e instanceof PropertyException) $status = self::STATUS_CODE['property_error'];
            elseif ($e instanceof csvColumnException) $status = self::STATUS_CODE['column_error'];
            else $status = self::STATUS_CODE['problem'];

            Log::error(__METHOD__ . 'error_message' . $e);
            $this->setStatus($status, $e);
        }

        return $this->result;
    }

    /* ===== Function definition (cannot be overridden) ===== */

    /**
     * Set the setting of SplFileObject received as an argument
     * @param SplFileObject $file
     * @return void
     */
    private final function setSplFileObjectFlags(SplFileObject &$file): void
    {
        $file->setFlags($this->splFileObjectFlags);
    }

    /**
     * Storage of result code and storage of fatal error contents
     * @param integer $statusCode
     * @param Throwable|null $e
     * @return void
     */
    private final function setStatus(int $statusCode, ?Throwable $e = null)
    {
        $this->result['status'] = $statusCode;

        if ($statusCode === self::STATUS_CODE['property_error']
        || $statusCode === self::STATUS_CODE['problem']
        || $statusCode === self::STATUS_CODE['column_error']) 
        {
            $this->result['error'] = $e;
        }
    }

    /**
     * Storage of error details
     * @param string $warningMessage
     * @return void
     */
    private final function setWarning(string $warningMessage): void
    {
        $this->result['warning'][] = $warningMessage;
    }

    /**
     * Store the extraction result received as an argument in [extracts] of "result".
     * If the received result is empty, store the error message with the line number in [warning].
     * @param array $value
     * @param int $index
     * @return void
     */
    private final function setExtracts(array &$value, int $index, bool $isInValid): void
    {
        if (!empty($value)  && !$isInValid) {

            $this->result['extracts'][] = $this->specialProcess($value);
        }
        else {
            $this->result['warning'][] = "{$index}Failed to extract the result of the line.";
        }
    }

    /**
     * Check if the number of items in the required properties "csvColumns" and "modelColumns" match
     * @return void
     */
    private final function propertyValidate(): void
    {
        if (empty($this->csvColumns)) {
            throw new PropertyException('[csvColumns] is undefined');
        } elseif (empty($this->modelColumns)) {
            throw new PropertyException('[modelColumns] is undefined');
        } elseif (count($this->csvColumns) !== count($this->modelColumns)) {
            throw new PropertyException('The counts in [csvColumns] and the counts in [modelColumns] do not match');
        } elseif (empty($this->encodings)) {
            throw new PropertyException('Required property [encodings] is not defined');
        }
    }

    /**
     * Performs a csv column (header) check.
     * If it passes the inspection normally, the CSV character code will be returned.
     * If the check fails, a "csvColumnsException" is thrown
     * @param array $line
     * @return string|bool
     */
    private final function columnValidate(array &$line): ?string
    {
        $diff = array_diff($line, $this->csvColumns);

        if (count($diff) > 0) throw new CsvColumnException('The number of columns in the uploaded csv does not match the expected value');

        $encode_suggest = $this->judgeEncodingType($line);

        if (!$encode_suggest) throw new CsvColumnException('The character code of the CSV file may have failed to be read, or the csv column may be partially invalid.');

        return $encode_suggest;
    }

    /**
     * Determine what the character code of the uploaded file is and return it
     * @param array $line
     * @return string|null
     */
    private final function judgeEncodingType(array $line): ?string
    {
        foreach ($this->encodings as $encode_suggest) {
            $header = $line;
            if ($this->encodeType !== $encode_suggest) mb_convert_variables($this->encodeType, $encode_suggest, $header);
            $is_match = true;
            // Check if the character code matches by comparing the encoded value with the value of "csvColumns".
            for ($i = 0; $i < count($this->csvColumns); $i++) {
                if ($header[$i] != $this->csvColumns[$i]) $is_match = false;
            }
            // Character code determination
            if ($is_match) return $encode_suggest;
        }

        return false;
    }

    /**
     * Perform validation on the extracted data
     * @param array $value
     * @return bool
     */
    private final function valueValidate(array &$value, int $index): bool
    {
        $validator = Validator::make(
            $value,
            $this->valueValidationRule(),
            $this->valueValidationMessage()
        );

        $isInvalid = $validator->fails();
        if ($isInvalid) {
            $this->result['invalid'][$index] = $validator->errors()->all();
        }

        return $isInvalid;
    }

    /**
     * Store the "file" object received as an argument in the "result" property
     * @param array $line
     * @return array
     */
    private final function extract(SplFileObject &$file): void
    {
        $conversionType = '';
        // Extract data from csv file
        foreach ($file as $line) {
            // Since the index number starts from 0, you need to add +1 to know the exact line number.
            $key = $file->key() + 1;

            // Suspension, column inspection, skip inspection
            if ($this->isBreak($line, $key)) {
                $this->setWarning("Since the maximum number of rows to be read ({$this->maxCsvRows}) has been exceeded, reading from the {$key} row onward was interrupted.");
                break;
            }
            if ($key === $this->csvColumnRowNumber) $conversionType = $this->columnValidate($line);
            if ($this->isSkipLine($line, $key)) continue;

            // Character code conversion
            $changed = mb_convert_variables($this->encodeType, $conversionType, $line);

            if ($changed === $conversionType) {
                $extract = $this->combineKeyValue($line);
                $isInValid = $this->valueValidate($extract, $key);
                $this->setExtracts($extract, $key, $isInValid);
            } else {
                $this->setWarning("Failed to extract the result because the character code conversion on the {$key} line failed.");
            }
        }
    }

    /**
     * The array data received as an argument is combined with the property "modelColumns" and returned.
     * The extraction result is as follows.
     * [key]: "modelColumns"
     * [value]: Data extracted from csv
     * @param array $line
     * @return array
     */
    private final function combineKeyValue(array &$line): array
    {
        $extract = [];
        if (count($this->modelColumns) === count($line)) $extract = array_combine($this->modelColumns, $line);

        return $extract;
    }

    /* ===== Function definition (overridable) ===== */

    /**
     * Determine if the line is to skip reading
     * @param array $line
     * @param int $index
     * @return boolean
     */
    protected function isSkipLine(array &$line, int $index): bool
    {
        if ($index === $this->csvColumnRowNumber) return true;
        else return false;
    }

    /**
     * Decide whether to interrupt the process
     * @param array $line
     * @param integer $index
     * @return boolean
     */
    protected function isBreak(array &$line, int $index): bool
    {
        if ($index >= $this->maxCsvRows) return true;
        else return false;
    }

    /**
     * Check the final result and store the status code.
     * Override here if you want to see the final result and throw the error again.
     * @return void
     */
    protected function setFinalResult(): void
    {
        if (empty($this->result['invalid']) && empty($this->result['warning']) && empty($this->result['error'])) {
            $status = self::STATUS_CODE['success'];
        } elseif (count($this->result['warning']) > 0) {
            $status = self::STATUS_CODE['partially_error'];
        } else {
            $status = self::STATUS_CODE['problem'];
        }

        $this->result['status'] = $status;
    }

    /**
     * !!! Override this function if you want to do something special with the extracted data !!!
     * This function is executed before the extraction result is stored in the "result" variable by "setExtracts".
     * Since the extraction result is received as an argument
     * This is useful when you want to add data on the system side.
     * By default, it will be returned without any processing.
     * @param array $value
     * @return array
     */
    protected function specialProcess(array $value): array
    {
        return $value;
    }
}
