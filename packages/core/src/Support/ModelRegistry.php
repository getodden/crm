<?php

declare(strict_types=1);

namespace Odden\Core\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Every Eloquent model (and the model-less tables) that the Odden packages own.
 *
 * Each package registers its models here from its service provider. A host application or an
 * add-on package such as multi-tenancy can then act on all Odden data from one explicit list,
 * for example to add a column and a global scope, or to check that nothing was missed, instead
 * of scanning directories or hard-coding class names.
 */
final class ModelRegistry
{
    /** @var array<class-string<Model>, true> */
    private array $models = [];

    /** @var array<string, true> */
    private array $tables = [];

    /**
     * Register every concrete Eloquent model in a directory, one class per file named after the class.
     */
    public function discover(string $directory, string $namespace): void
    {
        $files = glob(rtrim($directory, '/').'/*.php');

        foreach ($files === false ? [] : $files as $file) {
            $class = rtrim($namespace, '\\').'\\'.basename($file, '.php');

            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $this->models[$class] = true;
            }
        }
    }

    /**
     * Register concrete Eloquent models by class name.
     *
     * @param  class-string<Model>  ...$models
     */
    public function register(string ...$models): void
    {
        foreach ($models as $class) {
            if (! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
                throw new InvalidArgumentException("{$class} is not a concrete Eloquent model.");
            }

            $this->models[$class] = true;
        }
    }

    /**
     * Register tables that have no model (join tables written to directly).
     */
    public function registerTable(string ...$tables): void
    {
        foreach ($tables as $table) {
            $this->tables[$table] = true;
        }
    }

    /**
     * @return list<class-string<Model>>
     */
    public function models(): array
    {
        $models = array_keys($this->models);
        sort($models);

        return $models;
    }

    /**
     * Table names of every registered model, as configured in this install, plus the model-less tables.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $tables = $this->tables;

        foreach (array_keys($this->models) as $class) {
            $tables[(new $class)->getTable()] = true;
        }

        $names = array_keys($tables);
        sort($names);

        return $names;
    }
}
