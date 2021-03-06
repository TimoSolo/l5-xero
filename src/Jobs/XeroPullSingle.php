<?php
namespace Assemble\l5xero\Jobs;

use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Queue\ShouldQueue;

use Assemble\l5xero\Jobs\Job;
use Assemble\l5xero\Traits\XeroClassMap;
use Assemble\l5xero\Traits\XeroAPIRateLimited;
use Assemble\l5xero\Traits\UpdatesXeroModel;

use Assemble\l5xero\Xero;

use Log;
use DB;
use ReflectionClass;
use Cache;
use Carbon\Carbon;

class XeroPullSingle extends Job implements SelfHandling, ShouldQueue
{
    use InteractsWithQueue, SerializesModels, XeroClassMap, XeroAPIRateLimited, UpdatesXeroModel;


    protected $xero;
    protected $prefix;
    protected $model;
    protected $guid;
    protected $map;
    protected $callback;

    protected $saved = 0;
    protected $updated = 0;
    protected $deleted = 0;

    protected $xeroInstance;

    /**
     * Create a new job instance.
     *
     * @param  String $xero
     * @param  String $model
     * @param  String $guid
     * @param  Array $callback
     * @return void
     */
    public function __construct($xero, $model, $guid, $callback = null)
    {
        $this->xero = $xero;
        $this->prefix = 'Assemble\\l5xero\\Models\\';
        
        $map = $this->getXeroClassMap();
        $this->map = $map[$model];
        $this->model = $model;
        $this->guid = $guid;
        

        $this->callback = $callback;
        $class = $this->prefix.$this->model;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->rateLimit_canRun();
        switch (strtolower($this->xero)) {
            case 'private':
                $this->xeroInstance = new Xero($this->xero);
            break;
            case 'public':
                $this->xeroInstance = new Xero($this->xero);
            break;
            case 'partner':
                $this->xeroInstance = new Xero($this->xero);
            break;
            default:
                throw new \Assemble\l5xero\Exceptions\InvalidTypeException();
        } 

        try {
            $object = $this->xeroInstance->loadByGUID($this->map['MODEL'], $this->guid);                
            $this->processModel($this->model, $this->map, $object, null, null, true);
        }
        catch(\XeroPHP\Remote\Exception\UnauthorizedException $e)
        {
            \Log::error($e);
            echo 'ERROR: Xero Authentication Error. Check logs for more details.'.PHP_EOL;
            throw $e;
        }
    }

    

    /**
     * dispatces a callback job provided
     *
     * @param String $object
     * @param String $status
     *
     * @return void
     */
    private function queueCallback($object, $status, $original)
    {
            $job = (new ReflectionClass($this->callback))->newInstanceArgs([$object, $status, $original]);
            dispatch($job);
    }

    /**
     * Queries Xero by XeroID field on records to test for a 404 response
     *
     * @param String $model
     * @param String $GUID
     *
     * @return Boolean
     */
    private function testForXeroExistence($model, $GUID) {
        try {
            $this->xeroInstance->loadByGUID($model, $GUID);
            return true;
        } catch (\XeroPHP\Remote\Exception\NotFoundException $e) {
            return false;
        }
    }

    /**
     * Retrieves an existing record by unique fields
     *
     * @param String $model
     * @param Array $uniques
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function getUniqueOffendingRow($model, $uniques) {
        $item = (new $model);
        foreach($uniques as $key => $value) {
            $item = $item->orWhere($key, $value);
        }
        return $item->first();
    }

    /**
     * Processes a retrieved record and sub relations therein
     *
     * @param String $sub_key
     * @param Array $map
     * @param Array $obj
     * @param String $parent_key
     * @param Mixed $parent_value
     * @param Boolean $shallow
     *
     * @return void
     */
    private function processModel($sub_key, $map, $obj, $parent_key = null, $parent_value = null, $shallow = false)
    {
        $model = $this->prefix.$sub_key;
        $instance = (new $model);
        $items = [];
        $fillable = $instance->getFillable();
        $sub = ( isset($map['SUB']) ? $map['SUB'] : null);

        $last_updated = 0;
        $last_saved = 0;

        $original = [];
        //DO SAVE!
        try {
            
            $saved = $this->saveToModel($map['GUID'], $obj, $model, $fillable, $parent_key, $parent_value);
            $original = $saved->internal_original_attributes;

        } catch (\Illuminate\Database\QueryException $e) {
            // if its a unique constraint scenario ie: someone deleted a record and updated another one with the same unique fields
            if($e->getCode() == 23000) {
                \Log::info("Duplicate Record On Update: ".$e);
                $uniques = $this->getModelUniques($model, $obj);
                $offendingRow = $this->getUniqueOffendingRow($model, $uniques);
                if($offendingRow) {
                    $exists = $this->testForXeroExistence($map['MODEL'], $offendingRow->getAttributeValue($map['GUID']));
                    if(!$exists) {
                        try {
                            $offendingRow->delete();
                            $this->deleted++;
                            $saved = $this->saveToModel($map['GUID'], $obj, $model, $fillable, $parent_key, $parent_value);
                        } catch (Excepton $e) {
                            \Log::error("Unable to handle delete-update condition: ".$e);
                            return;
                        }
                    } else {
                        \Log::error("Duplicate Record On Update - Cannot Be Resolved: ".$e);
                        return;
                    }
                }
                return;
            } else {
                \Log::error("Failed To Store \"".$model."\" Level 1 - Query Exception");
                \Log::error($e);
                return;
            }
        } catch (Exception $e) {
            \Log::error("Failed To Store \"".$model."\" Level 1");
            \Log::error($e);
            return;
        }
        /*
        *   Run for collection of sub elements
        */
        if($sub != null && count($sub) > 0) {
            foreach($sub as $key => $sub_item)
            {
                if(isset($obj[$key.'s']) || isset($obj[$key]))
                {
                    //If the sub item kas the tag SINGLE then its a one-one relation so save directly
                    if( isset($sub_item['SINGLE']))
                    {
                        $model_sub = $this->prefix.$key;
                        $instance_sub = (new $model_sub);
                        $fillable_sub = $instance_sub->getFillable();
                        if($sub_item['SINGLE'] == 'HAS')
                        {
                            try {
                                $saved_sub = $this->saveToModel($sub_item['GUID'], $obj[$key], $model_sub, $fillable_sub, $sub_key.'_id', $saved->id);
                            } catch (Exception $e) {
                                \Log::error("Failed To Store \"".$model."\" Level 2");
                                \Log::error($e);
                                continue;
                            }
                        }
                        elseif($sub_item['SINGLE'] == 'BELONGS')
                        {
                            try {
                                $saved_sub = $this->saveToModel($sub_item['GUID'], $obj[$key], $model_sub, $fillable_sub);
                            } catch (Exception $e) {
                                \Log::error("Failed To Store \"".$model."\"  Level 3");
                                \Log::error($e);
                                continue;
                            }

                            $saved->{$key.'_id'} = $saved_sub->id;
                            $saved->save();
                            $original[$key] = $saved_sub->internal_original_attributes;
                        }

                    }
                    else // otherwise process the sub objects as one-many relations
                    {
                        $list_key = ( isset($obj[$key.'s']) ? $key.'s' : $key );
                        $sub_objs = $obj[$list_key];
                        
                        $saved->{$list_key} = [];
                        $original[$list_key] = [];

                        $model_sub = $this->prefix.$key;

                        $guids = collect($sub_objs)->pluck($sub_item['GUID']);
                        $this->deleted += $this->removeOrphanedRelations($sub_item['GUID'],$model_sub,$guids,$sub_key.'_id', $saved->id);

                        foreach($sub_objs as $sub_obj) {
                            $saved_obj = $this->processModel($key, $sub_item, $sub_obj, $sub_key.'_id', $saved->id);
                            $original[$list_key][] = $saved_obj->internal_original_attributes;
                        }
                    }
                        
                }
            }
        }

        // stats
        $this->saved += ( $saved->save_event_type == 1 ? 1 : 0 ); // saved
        $this->updated += ( $saved->save_event_type == 2 ? 1 : 0 ); // updates


        if($shallow == true && $this->callback != null && isset($this->callback) )
        {
            if($saved->save_event_type == 1)
            {
                $this->queueCallback($saved, 'create', $original);
            }
            elseif($saved->save_event_type == 2)
            {
                $this->queueCallback($saved, 'update', $original);
            }
        }

        return $saved;
    }
}