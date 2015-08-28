<?php
/*
 * $Id: database.inc.php,v 1.5 2005/12/06 09:18:22 youka Exp $
 */



/**
 * DB管理クラス。シングルトンのように振舞う。
 * 
 * sqlite関数のラッパー。失敗したときに例外を投げる。
 */
class DataBase
{
	protected $link;	//DBへのリンク
	protected $transaction = 0;	//トランザクションのネスト数
	
	
	/**
	 * インスタンスを取得する。
	 * @return  DataBase 	DataBaseのインスタンス。
	 */
	static function getinstance()
	{
		static $ins;
		
		if(empty($ins)){
			$ins = new self;
		}
		return $ins;
	}
	
	
	/**
	 * コンストラクタ。
	 */
	protected function __construct()
	{
		$file = WIKIID . '.db';
		$this->link = new SQLite3(DATA_DIR . $file, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE, $error);
		if($this->link == false){ // todo: unreachable
			clearstatcache();
			if(is_writable(DATA_DIR) == false){
				throw new FatalException('DATA_DIRへの書き込み権限がありません。', $error);
			}
			else if(is_writable(DATA_DIR . $file) == false){
				throw new FatalException('DBファイルへの書き込み権限がありません。', $error);
			}
			else{
				throw new FatalException('DBファイルを開けませんでした。', $error);
			}
		}
		$this->link->busyTimeout(5000);
		$this->link->createFunction('php', function(){
			$args = func_get_args();
			if (count($args) <= 0) {
				return null;
			}
			$func = array_shift($args);
			return call_user_func_array($func, $args);
		});
	}
	
	
	/**
	 * デストラクタ。
	 */
	function __destruct()
	{
		if($this->transaction > 0){
			$this->query('ROLLBACK');
		}
		if($this->link != false){
			$this->link->close();
		}
	}
	
	
	/**
	 * クエリを実行する。
	 * 
	 * @param	string	$query	SQL文。
	 * @return	Resource	$result	失敗した場合は例外を投げる。
	 */
	function query($query)
	{
		$result = $this->link->query($query);
		if($result == false){
			throw new DBException('クエリを実行できませんでした。', $query, $this->link);
		}
		return $result;
	}
	
	
	/**
	 * 結果を返さないクエリを実行する。
	 * 
	 * @param	string	$query	SQL文。
	 * @return	void
	 */
	function exec($query)
	{
		$result = $this->link->exec($query);
		if($result == false){
			throw new DBException('クエリを実行できませんでした。', $query, $this->link);
		}
	}
	
	
	/**
	 * クエリパラメータ用に文字列をエスケープする。
	 * 
	 * @param	string	$str	エスケープしたい文字列。
	 * @return	string	エスケープした文字列。
	 */
	function escape($str)
	{
		//空文字列をsqlite_escape_string()に渡すと謎の3バイトが帰ってくる(PHP5.0.0RC2以下)。
		//	http://bugs.php.net/bug.php?id=29339
		//	http://bugs.php.net/bug.php?id=29395
		return $str == '' ? '' : SQLite3::escapeString($str);
	}
	
	
	/**
	 * 直前のクエリにより変更されたレコード数を返す。
	 * 
	 * @return	int
	 */
	function changes()
	{
		return $this->link->changes();
	}
	
	
	/**
	 * "BEGIN TRANSACTION"を発行する。
	 */
	function begin()
	{
		if($this->transaction == 0){
			$this->exec("BEGIN TRANSACTION");
		}
		$this->transaction++;
	}
	
	
	/**
	 * "COMMIT"を発行する。
	 */
	function commit()
	{
		$this->transaction--;
		if($this->transaction == 0){
			$this->exec("COMMIT");
		}
	}
	
	
	/**
	 * そのテーブルが存在するかを確認する。
	 * 
	 * @param	string	$table	テーブル名
	 */
	function istable($table)
	{
		$_table = $this->escape($table);
		$query = "SELECT name FROM (SELECT name FROM sqlite_master WHERE type='table' UNION ALL SELECT name FROM sqlite_temp_master WHERE type='table') WHERE name = '$_table'";
		return $this->fetch($this->query($query)) !== false;
	}
	
	
	/**
	 * ユーザ関数を登録する（sqlite_create_function()ラッパー）。
	 */
	function create_function($function_name, $callback, $num_args = -1)
	{
		return $this->link->createFunction($function_name, $callback, $num_args);
	}
	
	
	/**
	 * 集約UDFを登録する（sqlite_create_aggregate()ラッパー）。
	 */
	function create_aggregate($function_name, $step_func, $finalize_func, $num_args = -1)
	{
		return $this->link->createAggregate($function_name, $step_func, $finalize_func, $num_args);
	}
	
	
	/**
	 * レコードを取得する。
	 * 
	 * @param Resource	$result	クエリの結果セット。
	 * @return	mixed	レコードデータを含む連想配列を返す。レコードが無い場合はfalseを返す。
	 */
	function fetch($result)
	{
		$ret = $result->fetchArray();
		if(get_magic_quotes_runtime()){
			return array_map('stripslashes', $ret);
		}
		return $ret;
	}

	
	/**
	 * レコードをすべて取得する。
	 * 
	 * @param Resource	$result	クエリの結果セット。
	 * @return	array(array(mixed))
	 */
	function fetchall($result)
	{
		$ret = array();
		while ($res = $this->fetch($result)) {
			$ret[] = $res;
		}
		return $ret;
	}
	
	
	/**
	 * レコードの先頭１カラム目をすべて取得する。
	 *
	 * @param Resource	$result	クエリの結果セット。
	 * @return	array(mixed)
	 */
	function fetchsinglearray($result)
	{
		$ret = array();
		while($res = $this->fetch($result)){
			$ret[] = $str[0];
		}
		return $ret;
	}
}


/**
 * SQLite関連の例外クラス。
 */
class DBException extends FatalException
{
	public function __construct($mes = '', $hiddenmes = '', $dblink)
	{
		clearstatcache();
		if(is_writable(DATA_DIR) == false){
			$mes = 'DATA_DIRへの書き込み権限がありません。' . $mes;
		}
		else if(is_writable(DATA_DIR . WIKIID . '.db') == false){
			$mes = 'DBファイルへの書き込み権限がありません。' . $mes;
		}
		
		parent::__construct($mes, linetrim($hiddenmes . "\n") . $dblink->lastErrorMsg());
	}
}


?>
