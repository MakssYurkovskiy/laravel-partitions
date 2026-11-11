<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Schema;

use Illuminate\Container\Container;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Support\Fluent;
use Yurkovskiy\Partitions\Exceptions\MissingDefinitionException;
use Yurkovskiy\Partitions\Exceptions\NotPartitionedException;
use Yurkovskiy\Partitions\Exceptions\UnsupportedGrammarException;
use Yurkovskiy\Partitions\PartitionBuilder;
use Yurkovskiy\Partitions\PartitionManager;

final class PartitionMacros
{
    public static function register(): void
    {
        if (! Blueprint::hasMacro('partitionByRange')) {
            Blueprint::macro('partitionByRange', function (string $column) {
                /** @var Blueprint $this */
                $definition = new PartitionDefinition(
                    PartitionMacros::manager()
                        ->newBuilder()
                        ->connection($this->connection->getName())
                        ->table($this->getTable())
                        ->column($column)
                );

                $this->addCommand('partitionBy', [
                    'definition' => $definition,
                    'pretending' => $this->connection->pretending(),
                ]);

                return $definition;
            });
        }

        if (! Blueprint::hasMacro('removePartitioning')) {
            Blueprint::macro('removePartitioning', function () {
                /** @var Blueprint $this */
                return $this->addCommand('removePartitioning', [
                    'partitionBuilder' => PartitionMacros::manager()
                        ->newBuilder()
                        ->connection($this->connection->getName())
                        ->table($this->getTable()),
                    'pretending' => $this->connection->pretending(),
                ]);
            });
        }

        if (! Grammar::hasMacro('compilePartitionBy')) {
            Grammar::macro('compilePartitionBy', function (Blueprint $blueprint, Fluent $command) {
                /** @var Grammar $this */
                PartitionMacros::assertSupported($this);

                $definition = $command->get('definition');

                if (! $definition instanceof PartitionDefinition) {
                    throw new MissingDefinitionException;
                }

                if (! $command->get('pretending')) {
                    $definition->assertApplicable($blueprint->creating());
                }

                return sprintf("ALTER TABLE %s\n%s", $this->wrapTable($blueprint), $definition->clause());
            });
        }

        if (! Grammar::hasMacro('compileRemovePartitioning')) {
            Grammar::macro('compileRemovePartitioning', function (Blueprint $blueprint, Fluent $command) {
                /** @var Grammar $this */
                PartitionMacros::assertSupported($this);

                $builder = $command->get('partitionBuilder');

                if (! $command->get('pretending') && $builder instanceof PartitionBuilder && ! $builder->isPartitioned()) {
                    throw NotPartitionedException::forMigration($builder->getTable());
                }

                return sprintf('ALTER TABLE %s REMOVE PARTITIONING', $this->wrapTable($blueprint));
            });
        }
    }

    /**
     * @throws UnsupportedGrammarException
     */
    public static function assertSupported(Grammar $grammar): void
    {
        if (! $grammar instanceof MySqlGrammar) {
            throw new UnsupportedGrammarException($grammar::class);
        }
    }

    public static function manager(): PartitionManager
    {
        return Container::getInstance()->make(PartitionManager::class);
    }
}
