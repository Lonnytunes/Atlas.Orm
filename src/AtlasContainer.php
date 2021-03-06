<?php
/**
 *
 * This file is part of Atlas for PHP.
 *
 * @license http://opensource.org/licenses/MIT MIT
 *
 */
namespace Atlas\Orm;

use Atlas\Orm\Mapper\MapperLocator;
use Atlas\Orm\Mapper\MapperEvents;
use Atlas\Orm\Relationship\Relationships;
use Atlas\Orm\Table\TableEvents;
use Atlas\Orm\Table\TableLocator;
use Atlas\Orm\Table\IdentityMap;
use Aura\Sql\ConnectionLocator;
use Aura\Sql\ExtendedPdo;
use Aura\SqlQuery\QueryFactory;

/**
 *
 * A container for setting up Atlas.
 *
 * @package atlas/orm
 *
 */
class AtlasContainer
{
    /**
     *
     * The Atlas instance managed by this container.
     *
     * @var Atlas
     *
     */
    protected $atlas;

    /**
     *
     * A locator for database connections.
     *
     * @var ConnectionLocator
     *
     */
    protected $connectionLocator;

    /**
     *
     * Custom object factories.
     *
     * @var array
     *
     */
    protected $factories = [];

    /**
     *
     * A locator for all Mapper objects.
     *
     * @var MapperLocator
     *
     */
    protected $mapperLocator;

    /**
     *
     * A factory for query objects.
     *
     * @var QueryFactory
     *
     */
    protected $queryFactory;

    /**
     *
     * A locator for all Table objects.
     *
     * @var TableLocator
     *
     */
    protected $tableLocator;

    /**
     *
     * Constructor.
     *
     * @param ExtendedPdo|PDO|string $dsn The data source name for a default
     * lazy PDO connection, or an existing database connection. If the latter,
     * the remaining params are ignored.
     *
     * @param string $username The default database connection username.
     *
     * @param string $password The default database connection password.
     *
     * @param array $options The default database connection options.
     *
     * @param array $attributes The default database connection attributes.
     *
     * @see ExtendedPdo::__construct()
     *
     */
    public function __construct(
        $dsn,
        $username = null,
        $password = null,
        array $options = [],
        array $attributes = []
    ) {
        $driver = $this->setConnectionLocator(func_get_args());
        $this->setQueryFactory($driver);
        $this->tableLocator = new TableLocator();
        $this->mapperLocator = new MapperLocator();
        $this->atlas = new Atlas(
            $this->mapperLocator,
            new Transaction($this->mapperLocator)
        );
    }

    /**
     *
     * Creates and sets the connection locator with a default connection.
     *
     * @param array $args Params for an building a default connection.
     *
     * @see ExtendedPdo::__construct()
     *
     */
    protected function setConnectionLocator(array $args)
    {
        switch (true) {

            case $args[0] instanceof ExtendedPdo:
                $extendedPdo = $args[0];
                $default = function () use ($extendedPdo) {
                    return $extendedPdo;
                };
                $driver = $extendedPdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                break;

            case $args[0] instanceof PDO:
                $extendedPdo = $args[0];
                $default = function () use ($pdo) {
                    return new ExtendedPdo($pdo);
                };
                $driver = $pdo->getAttribute(ExtendedPdo::ATTR_DRIVER_NAME);
                break;

            default:
                $default = function () use ($args) {
                    return new ExtendedPdo(...$args);
                };
                $parts = explode(':', $args[0]);
                $driver = array_shift($parts);
                break;
        }

        $this->connectionLocator = new ConnectionLocator($default);
        return $driver;
    }

    /**
     *
     * Returns the connection locator.
     *
     * @return ConnectionLocator
     *
     */
    public function getConnectionLocator()
    {
        return $this->connectionLocator;
    }

    /**
     *
     * Returns the mapper locator.
     *
     * @return MapperLocator
     *
     */
    public function getMapperLocator()
    {
        return $this->mapperLocator;
    }

    /**
     *
     * Creates and sets the query factory.
     *
     * @param string $db The database driver type.
     *
     */
    protected function setQueryFactory($db)
    {
        $this->queryFactory = new QueryFactory($db);
    }

    /**
     *
     * Returns the Atlas instance managed by this container.
     *
     * @return Atlas
     *
     */
    public function getAtlas()
    {
        return $this->atlas;
    }

    /**
     *
     * Sets a new "read" connection by name into the connection locator.
     *
     * @param string $name The connection name.
     *
     * @param callable $callable A callable to create and return the connection.
     *
     */
    public function setReadConnection($name, callable $callable)
    {
        $this->connectionLocator->setRead($name, $callable);
    }

    /**
     *
     * Sets a new "write" connection by name into the connection locator.
     *
     * @param string $name The connection name.
     *
     * @param callable $callable A callable to create and return the connection.
     *
     */
    public function setWriteConnection($name, callable $callable)
    {
        $this->connectionLocator->setWrite($name, $callable);
    }

    /**
     *
     * Sets multiple mappers into the mapper locator.
     *
     * @param array $mapperClasses An array of mapper class names for the
     * locator.
     *
     */
    public function setMappers(array $mapperClasses)
    {
        foreach ($mapperClasses as $mapperClass) {
            $this->setMapper($mapperClass);
        }
    }

    /**
     *
     * Sets one mapper into the mapper locator.
     *
     * @param string $mapperClass A mapper class names for the locator.
     *
     * @throws Exception when the mapper class does not exist.
     *
     * @throws Exception when the table class for a mapper does not exist.
     *
     */
    public function setMapper($mapperClass)
    {
        if (! class_exists($mapperClass)) {
            throw Exception::classDoesNotExist($mapperClass);
        }

        $tableClass = $mapperClass::getTableClass();
        if (! class_exists($tableClass)) {
            throw Exception::classDoesNotExist($tableClass);
        }
        $this->setTable($tableClass);

        $eventsClass = $mapperClass . 'Events';
        $eventsClass = class_exists($eventsClass)
            ? $eventsClass
            : MapperEvents::CLASS;

        $mapperFactory = function () use ($mapperClass, $tableClass, $eventsClass) {
            return new $mapperClass(
                $this->tableLocator->get($tableClass),
                new Relationships($this->getMapperLocator()),
                $this->newInstance($eventsClass)
            );
        };

        $this->mapperLocator->set($mapperClass, $mapperFactory);
    }

    /**
     *
     * Sets a table into the table locator.
     *
     * @param string $tableClass The table class name.
     *
     */
    protected function setTable($tableClass)
    {
        $eventsClass = $tableClass . 'Events';

        $eventsClass = class_exists($eventsClass)
            ? $eventsClass
            : TableEvents::CLASS;

        $factory = function () use ($tableClass, $eventsClass) {
            return new $tableClass(
                $this->connectionLocator,
                $this->queryFactory,
                new IdentityMap(),
                $this->newInstance($eventsClass)
            );
        };

        $this->tableLocator->set($tableClass, $factory);
    }

    /**
     *
     * Sets a custom factory for a class.
     *
     * @param string $class The class name.
     *
     * @param callable $callable A callable to create and return a new instance.
     *
     */
    public function setFactoryFor($class, callable $callable)
    {
        $this->factories[$class] = $callable;
    }

    /**
     *
     * Creates and returns a new instance.
     *
     * @param string $class Create and return an instance of this class.
     *
     * @return object
     *
     */
    public function newInstance($class)
    {
        if (isset($this->factories[$class])) {
            $factory = $this->factories[$class];
            return $factory();
        }

        return new $class();
    }
}
