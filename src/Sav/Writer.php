<?php

namespace SPSS\Sav;

use SPSS\Buffer;
use SPSS\Exception;
use SPSS\Utils;

class Writer
{
    /**
     * @var Record\Header
     */
    public $header;

    /**
     * @var Record\Variable[]
     */
    public $variables = [];

    /**
     * @var Record\ValueLabel[]
     */
    public $valueLabels = [];

    /**
     * @var Record\Document
     */
    public $document;

    /**
     * @var Record\Info[]
     */
    public $info = [];

    /**
     * @var Record\Data
     */
    public $data;

    /**
     * @var Buffer
     */
    protected $buffer;

    /**
     * Writer constructor.
     *
     * @param array $data
     * @param Buffer $buffer
     *
     */
    public function __construct($data = [], $buffer = null)
    {
        $this->buffer          = isset($buffer) ? $buffer : Buffer::factory();
        $this->buffer->context = $this;

        if (!empty($data)) {
            $this->write($data);
        }
    }
    
    /**
     * @param array $data
     * @param string $file
     *
     * @return Writer
     */
    public static function createInFile($data = [], $file)
    {
        return new self($data, Buffer::factory(fopen($file, 'wb+')));
    }

    public function write($data)
    {
        $this->header                  = new Record\Header($data['header']);
        $this->header->nominalCaseSize = 0;
        $this->header->casesCount      = 0;

        $this->info[Record\Info\MachineInteger::SUBTYPE] = $this->prepareInfoRecord(
            Record\Info\MachineInteger::class,
            $data
        );

        $this->info[Record\Info\MachineFloatingPoint::SUBTYPE] = $this->prepareInfoRecord(
            Record\Info\MachineFloatingPoint::class,
            $data
        );

        $this->info[Record\Info\VariableDisplayParam::SUBTYPE]  = new Record\Info\VariableDisplayParam();
        $this->info[Record\Info\LongVariableNames::SUBTYPE]     = new Record\Info\LongVariableNames();
        $this->info[Record\Info\VeryLongString::SUBTYPE]        = new Record\Info\VeryLongString();
        $this->info[Record\Info\ExtendedNumberOfCases::SUBTYPE] = $this->prepareInfoRecord(
            Record\Info\ExtendedNumberOfCases::class,
            $data
        );
        $this->info[Record\Info\VariableAttributes::SUBTYPE]      = new Record\Info\VariableAttributes();
        $this->info[Record\Info\LongStringValueLabels::SUBTYPE]   = new Record\Info\LongStringValueLabels();
        $this->info[Record\Info\LongStringMissingValues::SUBTYPE] = new Record\Info\LongStringMissingValues();
        $this->info[Record\Info\CharacterEncoding::SUBTYPE]       = new Record\Info\CharacterEncoding('UTF-8');

        $this->data = new Record\Data();

        $nominalIdx = 0;

        $variableNames = [];

        /** @var Variable $var */
        // for ($idx = 0; $idx <= $variablesCount; $idx++) {
        foreach (array_values($data['variables']) as $idx => $var) {
            if (\is_array($var)) {
                $var = new Variable($var);
            }

            //if (! preg_match('/^[A-Za-z0-9_]+$/', $var->name)) {
            // UTF-8 and '.' characters could pass here
            if (!preg_match('/^[A-Za-z0-9_\.\x{4e00}-\x{9fa5}]+$/u', $var->name)) {
                throw new \InvalidArgumentException(sprintf('Variable name `%s` contains an illegal character.', $var->name));
            }

            if (empty($var->width)) {
                throw new \InvalidArgumentException(sprintf('Invalid field width. Should be an integer number greater than zero.'));
            }

            $variable = new Record\Variable();

            $segmentCount = Utils::widthToSegments($var->width);

            $variable->name = $this->generateShortName($var->name, $variableNames);
            if ($segmentCount > 1) {
                $variable->segmentNamePrefix = $this->generateSegmentNamePrefix(
                    $variable->name,
                    $segmentCount,
                    $variableNames
                );
            }

            $variable->width = Variable::FORMAT_TYPE_A === $var->format ? $var->width : 0;

            $variable->label = $var->label;
            $variable->print = [
                0,
                $var->format,
                $var->width > 0 ? min($var->width, 255) : 8,
                $var->decimals,
            ];
            $variable->write = [
                0,
                $var->format,
                $var->width > 0 ? min($var->width, 255) : 8,
                $var->decimals,
            ];

            // TODO: refactory
            $shortName = $variable->name;
            $longName  = $var->name;

            if ($var->attributes !== []) {
                $this->info[Record\Info\VariableAttributes::SUBTYPE][$longName] = $var->attributes;
            }

            if ($var->missing !== []) {
                if ($var->width <= 8) {
                    if (\count($var->missing) >= 3) {
                        $variable->missingValuesFormat = 3;
                    } elseif (2 === \count($var->missing)) {
                        $variable->missingValuesFormat = -2;
                    } else {
                        $variable->missingValuesFormat = 1;
                    }
                    $variable->missingValues = $var->missing;
                } else {
                    $this->info[Record\Info\LongStringMissingValues::SUBTYPE][$shortName] = $var->missing;
                }
            }

            $this->variables[$idx] = $variable;

            if ($var->values !== []) {
                if ($variable->width > 8) {
                    $this->info[Record\Info\LongStringValueLabels::SUBTYPE][$longName] = [
                        'width'  => $var->width,
                        'values' => $var->values,
                    ];
                } else {
                    $valueLabel = new Record\ValueLabel([
                        'variables' => $this->variables,
                    ]);
                    foreach ($var->values as $key => $value) {
                        $valueLabel->labels[] = [
                            'value' => $key,
                            'label' => $value,
                        ];
                        $valueLabel->indexes = [$nominalIdx + 1];
                    }
                    $this->valueLabels[] = $valueLabel;
                }
            }

            $this->info[Record\Info\LongVariableNames::SUBTYPE][$shortName] = $var->name;

            if (Record\Variable::isVeryLong($var->width) !== false) {
                $this->info[Record\Info\VeryLongString::SUBTYPE][$shortName] = $var->width;
            }

            for ($i = 0; $i < $segmentCount; $i++) {
                $this->info[Record\Info\VariableDisplayParam::SUBTYPE][] = [
                    $var->getMeasure(),
                    $var->getColumns(),
                    $var->getAlignment(),
                ];
            }

            // TODO: refactory
            $dataCount = \count($var->data);

            if ($dataCount > $this->header->casesCount) {
                $this->header->casesCount = $dataCount;
            }

            foreach ($var->data as $case => $value) {
                $this->data->matrix[$case][$idx] = $value;
            }

            if (Variable::isNumberFormat($var->format)) {
                $nominalIdx += 1;
            } else {
                $nominalIdx += Utils::widthToOcts($var->width);
            }
        }

        $this->header->nominalCaseSize = $nominalIdx;

        // write header
        $this->header->write($this->buffer);

        // write variables
        foreach ($this->variables as $variable) {
            $variable->write($this->buffer);
        }

        // write valueLabels
        foreach ($this->valueLabels as $valueLabel) {
            $valueLabel->write($this->buffer);
        }

        // write documents
        if (!empty($data['documents'])) {
            $this->document = new Record\Document([
                    'lines' => $data['documents'],
                ]
            );
            $this->document->write($this->buffer);
        }

        foreach ($this->info as $info) {
            $info->write($this->buffer);
        }

        $this->data->write($this->buffer);
    }

    /**
     * @param $row
     *
     * @return void
     */
    public function writeCase($row)
    {
        if (!isset($this->data)) {
            $this->data = new Record\Data();
        }

        // update the header info about number of cases
        $this->header->increaseCasesCount($this->buffer);

        // write data
        $this->data->writeCase($this->buffer, $row);
    }

    /**
     * @param $file
     *
     * @return false|int
     */
    public function save($file)
    {
        return $this->buffer->saveToFile($file);
    }

    /**
     * @return bool
     */
    public function close()
    {
        if (isset($this->data)) {
            $this->data->close();
        }

        return $this->buffer->close();
    }

    /**
     * @return Buffer
     */
    public function getBuffer()
    {
        return $this->buffer;
    }

    /**
     * Generates a unique 8-character name for a physical variable record.
     *
     * The dictionary only has room for an 8 byte name, the real name is stored
     * separately in the long variable names record. Short names are the key
     * everything else is matched on, so they must be unique within the file.
     *
     * @param string $longName
     * @param array  $reserved names already taken, updated by this method
     *
     * @return string
     */
    private function generateShortName($longName, array &$reserved)
    {
        $name    = mb_strtoupper(mb_substr($longName, 0, 8));
        $counter = 0;

        while (isset($reserved[$name])) {
            $suffix = mb_strtoupper(base_convert($counter, 10, 36));
            $name   = mb_strtoupper(mb_substr($longName, 0, 8 - mb_strlen($suffix))) . $suffix;
            $counter++;
        }

        $reserved[$name] = true;

        return $name;
    }

    /**
     * Reserves the name prefix used for the extra segments of a very long
     * string variable.
     *
     * Segments are physical variables of their own, so their generated names
     * (prefix + segment number) have to be unique too. Prefer the first 5
     * characters of the short name, which is what SPSS itself writes, and fall
     * back to a generated prefix when any of those names is already taken.
     *
     * @param string $shortName
     * @param int    $segmentCount
     * @param array  $reserved names already taken, updated by this method
     *
     * @return string
     */
    private function generateSegmentNamePrefix($shortName, $segmentCount, array &$reserved)
    {
        $prefix  = mb_substr($shortName, 0, 5);
        $counter = 0;

        while (!$this->isSegmentNamePrefixFree($prefix, $segmentCount, $reserved)) {
            $suffix = mb_strtoupper(base_convert($counter, 10, 36));
            $prefix = mb_substr($shortName, 0, 5 - mb_strlen($suffix)) . $suffix;
            $counter++;
        }

        for ($segment = 0; $segment < $segmentCount - 1; $segment++) {
            $reserved[$prefix . $segment] = true;
        }

        return $prefix;
    }

    /**
     * @param string $prefix
     * @param int    $segmentCount
     * @param array  $reserved
     *
     * @return bool
     */
    private function isSegmentNamePrefixFree($prefix, $segmentCount, array $reserved)
    {
        for ($segment = 0; $segment < $segmentCount - 1; $segment++) {
            if (isset($reserved[$prefix . $segment])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $className
     * @param array  $data
     * @param string $group
     *
     * @throws Exception
     *
     * @return array
     */
    private function prepareInfoRecord($className, $data, $group = 'info')
    {
        if (!class_exists($className)) {
            throw new Exception('Unknown class');
        }
        $key = lcfirst(substr($className, strrpos($className, '\\') + 1));

        return new $className(
            isset($data[$group]) && isset($data[$group][$key]) ?
                $data[$group][$key] :
                []
        );
    }
}
