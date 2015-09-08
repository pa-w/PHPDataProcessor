# PHPDataProcessor
Collection of libraries to process and manage data from different sources.

The main purpose of this library is to make it easier to write "bots" that fetch data from different sources and using different formats.

Say your Police Department is releasing a CSV file that contains data about police-civilian encounters or 911 calls or something like that.

The structure and format of it can -and many many times will- differ from other Police Departments' from other cities so you want to create an "Abstraction Layer" that helps you puttogether all those data sources into a single one so you can later analyze it.

Let us say this abstraction layer will be used to represent each and all of the police encounters from all of the cities you are getting data from. This will be expressed as an object, like this:

```php
class Incident extends DataObject
{
	var $Id; 
	var $City;
	var $Date;
	var $Crime;
	var $Street;
	var $XStreet;
	var $Neighborhood;
	var $District;
	var $Area;
	var $Location;
	var $Firearm;
	var $Weapon;
}
```
So, for now, that must cover must of the cases that describe a police encounter but now we will have to create instances of this object for each of the encounters in the database but, wait, where is the data?

The structure looks something like this:

compnos  | naturecode | incident_type_description | main_crimecode | reptdistrict | reportingarea |      fromdate       | weapontype | shooting | domestic | shift | year | month | day_week |  ucrpart   |        x        |        y         |  streetname   |  xstreetname  |          location           
-----------|------------|---------------------------|----------------|--------------|---------------|---------------------|------------|----------|----------|-------|------|-------|----------|------------|-----------------|------------------|---------------|---------------|-----------------------------

So this means that our data does not match the source's data at all so we will have to process it a litte bit. This is done using a map, like this:

```php
$incidentsMap = array (
	"Id" => "compnos", 
	"Date" => "fromdate", 
	"Crime" => "main_crimecode", 
	"District" => "reptdistrict", 
	"Area" => "reportingarea", 
	"Location" => "location", 
	"Firearm" => "weapontype",
	"Weapon" => "weapontype",
	"Coordinates" => array ("x", "y")
); 
```
But who reads that map?

The answer is a DataObjectCollection which, as its name says, a Collection of DataObjects. This DataObjectCollection needs a DataSource in order to work. You define a DataSource like this
```php
$url = "http://example.com/police-encounters.csv";
$paginator = new DataPage ($url, 50000 /* how many items we gather */, 0 /*starting where?*/, '$limit' /* the name of the GET variable that receives the limit*/, '$offset' /*the offset var */);
// We know it is a CSV... 
$incidentsSource = new CSVDataSource ($paginator);
```
As you could assume, the datasource will "Paginate" though the DataSource, getting all the info that is available... 

Not lets define the Collection itself: 
```php
$incidentsCollection = new DataObjectCollection ($incidentsSource);
```

So, lets go back to the map we defined earlier and use it in the collection, like this:
```php
$incidentsCollection->MapToObject ('Incident', $incidentsMap, $incidentsFilter); 
```
But, wait, there is a new variable: $incidentsFilter! And that is used to filter the values that will be defined in the object. Like this:

```php
$incidentsFilter = array (
	"Weapon" => function ($w) { return $w != "Unarmed"; }, // this Closure takes $obj->Weapon as argument and returs true if it is something different as "Unarmed"
	"Firearm" => function ($w) { return $w == "Firearm"; }, //This one takes $obj->Firefarm, and returns true if it is "Firearm"
	"Crime" => function ($C) { return strtoupper ($C); }, // Simple one, convert to uppercase the $obj->Crime ..
	"Coordinates" => function ($a) { return "Point ($a)"; } // another simple one: wrap around Point () the $obj->Coordinates; 
);
```
Next to document: How to access the created objects, how to make branches and nests from them and then export them back to other things like CSVs and SQL.

