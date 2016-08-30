<?php
namespace Assemble\l5xero\Models;

use \Illuminate\Database\Eloquent\Model as Eloquent;

class ContactPerson extends Eloquent {

	 /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'lfivexero_contact_persons';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
		'FirstName',
		'LastName',
		'EmailAddress',
		'IncludeInEmails',
    ];


   	public function contact()
   	{
   		return $this->belongsTo('Assemble\l5xero\Models\Contact');
   	}

}