<?php

declare(strict_types=1);

namespace Alsofronie\Uuid;

use Webpatser\Uuid\Uuid;

/*
 * This trait is to be used with the DB::statement('ALTER TABLE table_name ADD COLUMN id BINARY(16) PRIMARY KEY')
 * @package Alsofronie\Uuid
 * @author Alex Sofronie <alsofronie@gmail.com>
 * @license MIT
 */

trait UuidBinaryModelTrait
{
    /*
     * This function is used internally by Eloquent models to test if the model has auto increment value
     * @returns bool Always false
     */
    public function getIncrementing(): bool
    {
        return false;
    }

    /**
     * This function overwrites the default boot static method of Eloquent models. It will hook
     * the creation event with a simple closure to insert the UUID
     */
    public static function bootUuidBinaryModelTrait(): void
    {
        static::creating(
            function ($model) {
                // Only generate UUID if it wasn't set by already.
                $key = $model->getKeyName();
                if (! isset($model->attributes[$key])) {
                    // This is necessary because on \Illuminate\Database\Eloquent\Model::performInsert
                    // will not check for $this->getIncrementing() but directly for $this->incrementing
                    $model->incrementing = false;
                    $uuidVersion = (! empty($model->uuidVersion) ? $model->uuidVersion : 4);   // defaults to 4
                    $uuid = Uuid::generate($uuidVersion);
                    $model->attributes[$key] = (property_exists(
                        $model,
                        'uuidOptimization'
                    ) && $model::$uuidOptimization ? $model::toOptimized(
                        $uuid->string
                    ) : $uuid->bytes);
                }
            }
        );
    }

    /**
     * Gets the binary field as hex string ($model->id_string)
     * @return string The string representation of the binary field.
     */
    public function getIdStringAttribute(): string
    {
        $key = $this->getKeyName();
        return (property_exists(
            $this,
            'uuidOptimization'
        ) && $this::$uuidOptimization)
            ? self::toNormal($this->attributes[$key]) : bin2hex(
                $this->attributes[$key]
            );
    }

    /**
     * Gets the binary field as string ($model->id_dashed_string) with dashes
     * @return string The string representation of the binary field.
     */
    public function getIdDashedStringAttribute(): string
    {
        $uuid = $this->getIdStringAttribute();

        return substr($uuid, 0, 8) . '-' .
            substr($uuid, 8, 4) . '-' .
            substr($uuid, 12, 4) . '-' .
            substr($uuid, 16, 4) . '-' .
            substr($uuid, 20);
    }

    /**
     * Modified find static function to accept both string and binary versions of uuid
     * @param  mixed $id       The id (binary or hex string)
     * @param  array $columns  The columns to be returned (defaults to *)
     * @return mixed           The model or null
     */
    public static function find($id, $columns = array('*'))
    {
        $key = (new static())->getKeyName();
        if (ctype_print($id)) {
            $idFinal = (property_exists(
                static::class,
                'uuidOptimization'
            ) && static::$uuidOptimization)
                ? self::toOptimized($id) : hex2bin($id);

            return static::where($key, '=', $idFinal)->first($columns);
        }

        return parent::where($key, '=', $id)->first($columns);
    }

    /**
     * Modified findOrFail static function to accept both string and binary versions of uuid
     * @param  mixed $id       The id (binary or hex string)
     * @param  array $columns  The columns to be returned (defaults to *)
     * @return mixed           The model or null
     */
    public static function findOrFail($id, $columns = array('*'))
    {
        $key = (new static())->getKeyName();
        if (ctype_print($id)) {
            $idFinal = (property_exists(
                static::class,
                'uuidOptimization'
            ) && static::$uuidOptimization)
                ? self::toOptimized($id) : hex2bin($id);

            return static::where($key, '=', $idFinal)->firstOrFail($columns);
        }

        return parent::where($key, '=', $id)->firstOrFail($columns);
    }

    /**
    * Convert the model to an array
    * @return array An array containing all the fields of the model
    */
    public function toArray(): array
    {
        $parentArray = parent::toArray();
        return $this->deepArray($parentArray);
    }

    private function deepArray($array)
    {
        $useOptimization = !empty($this::$uuidOptimization);
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->deepArray($value);
            } elseif (is_object($value) && method_exists($value, 'toArray')) {
                $array[$key] = $value->toArray();
            } elseif (
                is_string($value) && mb_detect_encoding(
                    $value,
                    'ASCII',
                    true
                ) === false
            ) {
                // mb_detect_encoding will return false if $value is a binary type
                $array[$key] = $useOptimization ? self::toNormal($value) : bin2hex(
                    $value
                );
            }
        }
        return $array;
    }

    /**
     * Convert uuid string (with or without dashes) to binary
     *
     * @param string $uuid
     *
     * @return string
     */
    public static function toOptimized(string $uuid): string
    {
        $uuid = preg_replace('/\-/', null, $uuid);
        return hex2bin(substr($uuid, 12, 4)) .
            hex2bin(substr($uuid, 8, 4)) .
            hex2bin(substr($uuid, 0, 8)) .
            hex2bin(substr($uuid, 16, 4)) .
            hex2bin(substr($uuid, 20));
    }

    /**
     * Convert uuid binary to string (without dashes)
     *
     * @param string $uuid
     *
     * @return string
     */
    public static function toNormal(string $uuid): string
    {
        return bin2hex(substr($uuid, 4, 4)) .
            bin2hex(substr($uuid, 2, 2)) .
            bin2hex(substr($uuid, 0, 2)) .
            bin2hex(substr($uuid, 8, 2)) .
            bin2hex(substr($uuid, 10));
    }

    public function fromJson($json, $asObject = false)
    {
        $mixed = parent::fromJson($json, $asObject);
        $key = $this->getKeyName();
        if ($asObject && property_exists($mixed, $key)) {
            $mixed->{$key} = static::toOptimized($mixed->{$key});
        } elseif (array_key_exists($key, $mixed)) {
            $mixed[$key] = static::toOptimized($mixed[$key]);
        }

        return $mixed;
    }
}
