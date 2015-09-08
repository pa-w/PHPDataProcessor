<?php
class DataPage {
	public $Offset = 0;
	public $Limit = 0;
	public $Url;
	private $LimitVarName;
	private $OffsetVarName;
	public function __construct ($baseUrl, $limit = 10, $offset = 0, $limitVarName = '', $offsetVarName = '') {
		$this->Url = $baseUrl;
		$this->Limit = $limit;
		$this->Offset = $offset; 
		$this->LimitVarName = $limitVarName;
		$this->OffsetVarName = $offsetVarName;
	}
	public $Page = 0;
	public $TotalItems = 0;
	public function GetUrl ($numItems = 0) {
		$queryArray = array ();
		if (!empty ($this->LimitVarName) && !empty ($this->Limit)) $queryArray [$this->LimitVarName] = $this->Limit;
		if (!empty ($this->OffsetVarName) && !empty ($this->Offset)) $queryArray [$this->OffsetVarName] = $this->Offset;

		$url = new http\Url($this->Url, array ("query" => http_build_query ($queryArray)));
		$this->Paginate ();
		$this->TotalItems += $numItems;
		$this->HasRows = ($this->TotalItems >= ($this->Limit * $this->Page)); 
		$this->Page ++;

		return $url->toString ();
	}
	public function Paginate () {
		$this->Offset += $this->Limit;
	}
}
class DataObject {
	public function GetWeight () {
		return 1;
	}
}
class DataNest {
	private $Key;
	public function __construct ($key) {
		$this->Key = $key;
	}
	public $Nests = array ();
	function AddNest (DataNest $nest) {
		$this->Nests [] = $nest;
	}
	private $Rollups = array();
	function AddRollup ($callback, $aggregates) {
		$this->Rollups [] = array ("callback" => $callback, "aggregates" => $aggregates, "values" => array (), "results" => array());
	}
	public $Rows = array ();
	public $Columns = array ();
	function AddObject (DataObject &$obj, array &$hierarchy) {
		if (is_array ($this->Key)) {
			$key = array_keys ($this->Key) [0];
		}
		$key = null;
		$keyName = null;
		if (is_string ($this->Key)) {
			$key = $obj->{$this->Key};
			$keyName = $this->Key;
		} elseif (is_array ($this->Key)) {
			$keyName = array_keys ($this->Key) [0];	
			//TODO add some checks here to make sure they're callable AND de-boiler-plate the next condition.
			$fnName = array_values ($this->Key) [0];
			$key = $fnName ($obj);
		} elseif ($this->Key instanceof Closure) {
			$keyName = "AnonFunc";
			$fnName = $this->Key;
			$key = $fnName ($obj);
		}
		if (!is_null ($key)) {
			if (!in_array ($keyName, $this->Columns)) {
				$this->Columns [] = $keyName;
			}
			if (!array_key_exists ("branches", $hierarchy)) {
				$hierarchy ["branches"] = array ();
			}
			if (!array_key_exists ($key, $hierarchy ["branches"])) {
				$hierarchy ["branches"] [$key] = array ();
			}
			$nestCnt = 0;
			foreach ($this->Nests as &$nest) {
				$nest->AddObject ($obj, $hierarchy ["branches"] [$key]);
				$nestCnt ++;
			}
			$rollupCnt = 0;
			// We first need to call the callback that "weighs" the object to, then, roll them up... 
			foreach ($this->Rollups as &$rollup) {
				$fnName = $rollup["callback"];
				$val = $fnName ($obj);
				$hierarchy ["branches"] [$key] ["rollups"] [$rollupCnt] ["results"] [] = $val;
				$rollupCnt ++;
			}
			$rollupCnt = 0;
			foreach ($this->Rollups as &$rollup) {
				foreach ($rollup ["aggregates"] as $aggregate => $closure) {
					$res = null;
					$aggregateName = is_int ($aggregate) ? $closure : $aggregate;
					if (!is_null ($closure) && is_callable ($closure)) {
						$res = $closure ($hierarchy ["branches"] [$key] ["rollups"] [$rollupCnt] ["results"]);
					}
					$hierarchy ["branches"] [$key] ["rollups"] [$rollupCnt] ["data"] [$aggregateName] = $res;
				}
				$rollupCnt ++;
			}
			$this->Rows [] =& $row;
		}
	}
	/*
	 * HUGE TODO!! 
	 * GetColumns and ExportCSV
	 * This does not support "multi branches" - just linear, one to one branching... Soon.
	*/
	private function GetColumns () {
		$columns = $this->Columns;
		foreach ($this->Nests as &$nest) {
			foreach ($nest->Rollups as &$rollup) {
				$columns = array_merge ($columns, $nest->Columns);
				// at first i intended to save array_keys(aggregates) as column names but this supports keys and values as columnNames (case: count)
				foreach ($rollup ["aggregates"] as $aggregate => $closure) {
					$aggregateName = is_int ($aggregate) ? $closure : $aggregate;
					$columns [] = $aggregateName;
				}
			}
		}
		return $columns;
	}
	function ExportCSV ($fileName) {
		$file = fopen ($fileName, "w");
		$flattenArray = function ($arr, $level = 0, $keys = array ()) use (&$flattenArray, &$file) {
			if (array_key_exists ("rollups", $arr)) {
				foreach ($arr ["rollups"] as $rollup) {
					if (array_key_exists ("data", $rollup)) {	
						$ds = array ();
						foreach ($rollup ["data"] as $aggName => $d) {
							$ds [] = $d;
						}
						fputcsv ($file, array_merge ($keys, $ds)); //write directly to the file 
					}
				}
			}
			if (array_key_exists ("branches", $arr)) {
				foreach ($arr ["branches"] as $branchKey => $branch) {
					$keys [$level] = $branchKey;
					$flattenArray ($branch, $level + 1, $keys);
				}
			}
		};
		$r = $flattenArray ($this->Objects);
		fclose ($file);
	}
	public $Objects = array ();
	public function SetObjects (&$objects) {
		$this->Objects = $objects;
	}
}
class DataObjectCollection {
	private $Source;
	public $Nests = array ();
	public function __construct (DataSource $source) {
		$this->Source = $source;
		$this->Source->Fetch ();
	}
	public function AddNest (DataNest &$nest) {
		$this->Nests [] =& $nest;
	}
	public function MapToObject ($objectType, $map = array(), $filters = array ()) {
		if (class_exists ($objectType)) {
			foreach ($this->Source->Data as $datum) {
				$a =& new $objectType ();
				foreach ($map as $objMember => $dataMember) {
					$data = null;
					if (is_array ($dataMember)) {
						// this supports arrays like:
						// array ("columns" => array ("col1", "col2"), "glue" => " - ");
						// array ("columns" => array ("col3", "col4"), "glue" => function ($a, $b) { return $a * $b; });
						// or like this, using defaults: 
						// array ("a", "b", "c") // will concat "a" "b" and "c" columns with a "," resulting in "{$a},{$b},{$c}"
						$glue = ",";
						$joinCols = $dataMember;
						if (array_key_exists ("columns", $dataMember)) $joinCols = $dataMember ["columns"];
						if (array_key_exists ("glue", $dataMember)) $glue = $dataMember ["glue"];
						$val = array ();
						foreach ($joinCols as $col) {
							$val [] = $datum [$col];
						}
						if (is_string ($glue)) {
							$data = implode ($glue, $val);	
						} elseif ($glue instanceof Closure) {
							$data = call_user_func_array ($glue, $val);
						}
					} else {
						$data = $datum [$dataMember];
					}
					if (array_key_exists ($objMember, $filters) && is_callable ($filters[$objMember])) {
						$fnName = $filters[$objMember];
						$data = $fnName ($data);
					}
					$a->$objMember = $data;
				}
				$this->AddObject ($a);
			}
			foreach ($this->Nests as $index => &$nest) {
				$nest->SetObjects ($this->Objects [$index]);
			}
		}
	}
	public $Objects = array ();
	public function AddObject (DataObject &$obj) {
		// Nests objects...
		foreach ($this->Nests as $index => &$nest) {
			if (empty ($this->Objects [$index])) {
				$this->Objects [$index] = array ();
			}
			$nest->AddObject ($obj, $this->Objects [$index]);
		}
	}
	private function GetNestIndex (&$nest) {
		foreach ($this->Nests as $i => &$n) {
			if ($n == $nest) {
				return $i;
			}
		}
		return -1;
	}
	public function ExportCSV ($fileName, $nest) {
		$args = func_get_args ();
		return $nest->ExportCSV ($fileName); //dont like that we need to send the Objects chun

	}
}
class DataSource {
	protected $Type;
	private $Url;
	private $Pagination;
	function __construct (DataPage $pagination)
	{
		$this->Pagination = $pagination;
	}
	public $RowsCount = 0;
	public $Rows = array ();
	public $Cols = array ();
	protected function SetCols ($cols) {
		$this->Cols = $cols;
		$this->ColsCount = count ($cols);
	}
	public $Raw;
	public $Data = array ();
	public function Fetch ($array = false) {
		do {
			if ($array) {
				$url = $this->Pagination->GetUrl ($this->RowsCount);
				$this->Rows = file ($url);
				$this->RowsCount = count ($this->Rows);
			} else {
				$this->Raw = file_get_contents ($this->Pagination->GetUrl ());
				$this->Data = $this->Raw;
			}
		} while ($this->Pagination->HasRows); 
	}
}
class CSVDataSource extends DataSource
{
	protected $Type = "CSV";
	public function Fetch () {
		parent::Fetch (true);
		if ($this->RowsCount > 0) {
			$this->SetCols (str_getcsv ($this->Rows [0]));
			for ($i = 1; $i < $this->RowsCount; $i++) {
				$this->Data [] = array_combine ($this->Cols, str_getcsv ($this->Rows [$i]));
			}
		}
	}
}
class JSONDataSource extends DataSource
{
	protected $Type = "JSON";
}
?>
