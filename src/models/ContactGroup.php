<?php
namespace \Assemble\l5xero\Models;

use \Illuminate\Database\Eloquent\Model as Eloquent;

class ContactGroup extends Eloquent {
    
    private $prefix = 'lfivexero_';

	 /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = $prefix.'contact_groups';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
		'Name',
		'Status',
		'ContactGroupID',
    ];


   	public function contacts()
   	{
   		return $this->hasMany('\Assemble\l5xero\Models\Contact');
   	}

   	

}