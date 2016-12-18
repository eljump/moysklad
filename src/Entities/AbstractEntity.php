<?php

namespace MoySklad\Entities;

use MoySklad\Components\Fields\EntityRelation;
use MoySklad\Components\MassRequest;
use MoySklad\Components\FilterQuery;
use MoySklad\Components\Specs\ConstructionSpecs;
use MoySklad\Components\Specs\LinkingSpecs;
use MoySklad\Components\Specs\QuerySpecs;
use MoySklad\Lists\EntityList;
use MoySklad\MoySklad;
use MoySklad\Components\Fields\EntityFields;
use MoySklad\Components\EntityLinker;
use MoySklad\Providers\RequestUrlProvider;

abstract class AbstractEntity implements \JsonSerializable {
    public static $entityName = '_a_entity';
    public $fields;
    public $links;
    /**
     * @var EntityRelation|null $relations
     */
    public $relations = null;
    protected $skladInstance;

    public function __construct(MoySklad &$skladInstance, $fields = [], ConstructionSpecs $specs = null)
    {
        if ( !$specs ) {
            $specs = ConstructionSpecs::create();
        }
        if ( is_array($fields) === false && is_object($fields) === false) $fields = [$fields];
        $this->fields = new EntityFields($fields);
        $this->links = new EntityLinker();
        $this->skladInstance = $skladInstance;
        $this->relations = new EntityRelation([]);
        $this->processConstructionSpecs($specs);
    }

    protected function processConstructionSpecs(ConstructionSpecs $specs){
        if ( $specs->relations ){
            $this->relations = EntityRelation::createRelations($this->skladInstance, $this);
            foreach ( $this->relations->getInternal() as $k=>$v ){
                $this->fields->deleteKey($k);
            }
        }
    }

    /**
     * @param $targetClass
     * @return mixed| AbstractEntity
     */
    public function transformToClass($targetClass){
        return new $targetClass($this->skladInstance, $this->fields->getInternal());
    }

    /**
     * @return $this|mixed|AbstractEntity
     */
    public function transformToMetaClass(){
        $eMeta = $this->getMeta();
        if ( $eMeta ){
            return $this->transformToClass(
                $eMeta->getClass()
            );
        }
        return $this;
    }

    /**
     * @return \MoySklad\Components\Fields\MetaField|null
     */
    public function getMeta(){
        return $this->fields->getMeta();
    }

    /**
     * @return static
     */
    public function update(){
        $res = $this->skladInstance->getClient()->put(
            RequestUrlProvider::instance()->getUpdateUrl(static::$entityName, $this->id),
            $this->mergeFieldsWithLinks()
        );
        return new static($this->skladInstance, $res);
    }

    /**
     * @return AbstractEntity
     */
    public function fresh(){
        $eId = $this->getMeta()->getId();
        return static::byId($this->skladInstance, $eId);
    }

    /**
     * @param MoySklad $skladInstance
     * @param array $queryParams
     * @return array|EntityList
     */
    public static function getList(MoySklad &$skladInstance, QuerySpecs $querySpecs = null){
        if ( !$querySpecs ) $querySpecs = QuerySpecs::create([]);
        return self::recursiveRequest(function($skladInstance, $querySpecs){
            return $skladInstance->getClient()->get(
                RequestUrlProvider::instance()->getListUrl(static::$entityName),
                $querySpecs->toArray()
            );
        }, $skladInstance, $querySpecs);
    }


    public static function filter(MoySklad &$skladInstance, FilterQuery $filterQuery, QuerySpecs $querySpecs = null){
        if ( !$querySpecs ) $querySpecs = QuerySpecs::create([]);
        return self::recursiveRequest(function($skladInstance, $querySpecs, $filterQuery){
            return $skladInstance->getClient()->get(
                RequestUrlProvider::instance()->getFilterUrl(static::$entityName),
                array_merge($querySpecs->toArray(), [
                    "filter" => $filterQuery->getRaw()
                ])
            );
        }, $skladInstance, $querySpecs, [
            $filterQuery
        ]);
    }

    /**
     * @param callable $method
     * @param MoySklad $skladInstance
     * @param QuerySpecs $queryParams
     * @param array $methodArgs
     * @return EntityList
     */
    protected static function recursiveRequest(callable $method, MoySklad $skladInstance, QuerySpecs $queryParams, $methodArgs = []){
        $res = call_user_func_array($method, array_merge([$skladInstance, $queryParams], $methodArgs));
        $resultingObjects = (new EntityList($skladInstance, $res->rows))
            ->map(function($e) use($skladInstance){
                return new static($skladInstance, $e);
            });
        if ( $res->meta->size > $queryParams->limit + $queryParams->offset ){
            $newQueryParams = QuerySpecs::create([
                "offset" => $queryParams->offset + QuerySpecs::MAX_LIST_LIMIT
            ]);
            $resultingObjects = $resultingObjects->merge(self::recursiveRequest($method, $skladInstance, $newQueryParams, $methodArgs));
        }
        return $resultingObjects;
    }

    /**
     * @param MoySklad $skladInstance
     * @param $id
     * @return AbstractEntity
     */
    public static function byId(MoySklad &$skladInstance, $id){
        $res = $skladInstance->getClient()->get(
          RequestUrlProvider::instance()->getByIdUrl(static::$entityName, $id)
        );
        return new static($skladInstance, $res);
    }

    public function mergeFieldsWithLinks(){
        $res = [];
        $links = $this->links->getLinks();
        foreach ($this->fields->getInternal() as $k => $v){
            $res[$k] = $v;
        }
        foreach ( $links as $k=>$v ){
            $res[$k] = $v;
        }
        return $res;
    }

    public function copyRelationsToLinks(){
        foreach ($this->relations->getInternal() as $k=>$v){
            $this->links->link($v, LinkingSpecs::create([
                "name" => $k
            ]));
        }
        return $this;
    }

    public function getSkladInstance(){
        return $this->skladInstance;
    }
    
    function jsonSerialize()
    {
        return $this->fields;
    }

    function __get($name)
    {
        return $this->fields->{$name};
    }

    function __set($name, $value)
    {
        $this->fields->{$name} = $value;
    }

    function __isset($name)
    {
        return isset($this->fields->{$name});
    }
}