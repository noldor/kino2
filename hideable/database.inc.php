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
		try {
			$file = WIKIID . '.db';
			$this->link = new SQLite3(DATA_DIR . $file, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
		} catch (Exception $ex) {
			throw new FatalException('DBファイルを開けませんでした。', (string)$ex);
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
		return SQLite2Escape::escape_string($str);
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
		if(!is_array($ret)){
			return $ret;
		}

		$ret = array_map(array('SQLite2Escape', 'unescape_string'), $ret);
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


/**
 * SQLite2のエスケープ系関数の再実装
 *
 * SQLite3::escapeString()はバイナリセーフではないため使えない。
 *   https://bugs.php.net/bug.php?id=63419
 *   https://bugs.php.net/bug.php?id=62361
 */
class SQLite2Escape
{
	static function escape_string($str)
	{
		$str = (string)$str;
		if(strlen($str) <= 0){
			return '';
		}

		if($str[0] == "\x01" || strpos($str, "\0") !== false){
			return "\x01" . self::encode_binary($str);
		}
		return str_replace("'", "''", $str);
	}

	static function unescape_string($str)
	{
		$str = (string)$str;
		if(strlen($str) <= 0){
			return '';
		}

		if($str[0] == "\x01"){
			return self::decode_binary(substr($str, 1));
		}
		return $str;
	}

	static function encode_binary($str)
	{
		$str = (string)$str;
		if(strlen($str) <= 0){
			return 'x';
		}

		// 文字列への[]アクセスはPHPマニュアル「文字列への文字単位のアクセスと修正」参照
		// http://php.net/manual/ja/language.types.string.php#language.types.string.substr
		$cnt = array_fill(0, 256, 0);
		for($i = strlen($str) - 1; $i >= 0; $i--){
			$cnt[ord($str[$i])]++;
		}

		$min = strlen($str);
		$e = 0;
		for($i = 1; $i < 256; $i++){
			if($i == ord("'")){
				continue;
			}
			$sum = $cnt[$i] + $cnt[($i+1)&0xff] + $cnt[($i+ord("'"))&0xff];
			if($sum < $min){
				$min = $sum;
				$e = $i;
				if($min == 0){
					break;
				}
			}
		}
		$ret = chr($e);
		$len = strlen($str);
		for($i = 0; $i < $len; $i++){
			$x = chr((ord($str[$i]) - $e) & 0xff);
			if ($x == "\0" || $x == "\x01" || $x == "'") {
				$ret .= "\x01";
				$x = chr((ord($x)+1) & 0xff);
			}
			$ret .= $x;
		}
		return $ret;
	}

	static function decode_binary($str)
	{
		$str = (string)$str;
		if(strlen($str) <= 0){
			return '';
		}

		$e = ord($str[0]);
		$len = strlen($str);
		$ret = '';
		for($i = 1; $i < $len; $i++){
			if($str[$i] == "\x01"){
				$c = ord($str[++$i]) - 1;
			} else {
				$c = ord($str[$i]);
			}
			$ret .= chr(($c + $e) & 0xff);
		}
		return $ret;
	}
}
