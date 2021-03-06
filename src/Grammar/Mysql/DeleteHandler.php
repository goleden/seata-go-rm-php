<?php
/**
 * @user: ligongxiang (ligongxiang@rouchi.com)
 * @date : 2020/5/4
 * @version : 1.0
 * @file : DeleteHandler.php
 * @desc :
 */

namespace ResourceManager\Grammar\Mysql;


use ResourceManager\Exceptions\MysqlGrammarException;

/**
 * Delete语句解析类
 * 错误码：[11200-11300)
 * */
class DeleteHandler
{
    const KEYWORDS = [
        'DELETE',
        'LOW_PRIORITY',
        'QUICK',
        'IGNORE',
        'FROM',
        'WHERE',
        'ORDER BY',
        'LIMIT',
    ];
    protected $sqlType = 'DELETE';
    protected $sqlStruct = null;
    protected $originSql = '';
    protected $keywordMap = array();
    protected $wordList = array();

    protected $columnNumbers = 0;
    protected $tableIndex = 0;

    /**
     * 初始化
     * @param $sql string 待处理语句
     * @throws MysqlGrammarException
     */
    public function __construct($sql)
    {
        $this->originSql = $sql;
        $this->spiltWords();
    }

    /**
     * 获取SQLStruct对象
     *
     * @throws MysqlGrammarException
     */
    public function getSQLStruct()
    {
        if (!empty($this->sqlStruct))
            return $this->sqlStruct;
        $this->sqlStruct = new SQLStruct($this->originSql);
        $this->sqlStruct->setSqlType($this->sqlType);
        $this->sqlStruct->setTable($this->findTable());
        $this->sqlStruct->setConditionsStr($this->findFullConditionStr());
        return $this->sqlStruct;
    }

    /**
     * 根据keywordMap找到表名，去除可能分词时自动包裹的'
     *
     * @throws MysqlGrammarException 11100,语法错误，找不到表名
     */
    protected function findTable()
    {
        if (isset($this->keywordMap['FROM'])) {
            $this->tableIndex = $this->keywordMap['FROM'];
            return trim($this->wordList[$this->keywordMap['FROM']],'\'');
        }
        throw new MysqlGrammarException(11200,'delete sql syntax error, cant find table');
    }

    /**
     * 根据keywordMap找到where条件语句
     */
    protected function findFullConditionStr()
    {
        //构造一个反向map
        $revertKeywordMap = array();
        foreach ($this->keywordMap as $k=>$v) {
            $revertKeywordMap[$v] = $k;
        }
        $fullConditionStr = '';
        if (isset($this->keywordMap['WHERE'])) {
            for ($i=$this->keywordMap['WHERE'];$i<count($this->wordList);$i++) {
                if (isset($revertKeywordMap[$i])) {
                    $fullConditionStr .= $revertKeywordMap[$i].' '.$this->wordList[$i].' ';
                    continue;
                }
                $fullConditionStr .= $this->wordList[$i].' ';
            }
            return $fullConditionStr;
        }
        if (isset($this->keywordMap['ORDER BY'])) {
            for ($i=$this->keywordMap['ORDER BY'];$i<count($this->wordList);$i++) {
                if (isset($revertKeywordMap[$i])) {
                    $fullConditionStr .= $revertKeywordMap[$i].' '.$this->wordList[$i].' ';
                    continue;
                }
                $fullConditionStr .= $this->wordList[$i].' ';
            }
            return $fullConditionStr;
        }
        if (isset($this->keywordMap['LIMIT'])) {
            for ($i=$this->keywordMap['LIMIT'];$i<count($this->wordList);$i++) {
                if (isset($revertKeywordMap[$i])) {
                    $fullConditionStr .= $revertKeywordMap[$i].' '.$this->wordList[$i].' ';
                    continue;
                }
                $fullConditionStr .= $this->wordList[$i].' ';
            }
            return $fullConditionStr;
        }
        return $fullConditionStr;
    }

    /**
     * 分词，将sql解析成单词并填充到对应的关键字map,普通词组
     *
     * @throws MysqlGrammarException 11000,11001 sql为空,语法错误
     */
    protected function spiltWords()
    {
        $strLen = strlen($this->originSql);
        if ($strLen == 0) {
            throw new MysqlGrammarException(11000,'update sql is empty');
        }
        $letters = array();
        $symbolTemp = null;
        for ($i=0;$i<$strLen;$i++) {
            $letter = $this->originSql[$i];
            //匹配中的非对应配对字符，全部计入字符数组
            if ($symbolTemp !== null && !in_array($letter,MysqlGrammar::PAIR_SIGN)) {
                array_push($letters,$letter);
                continue;
            }
            //匹配中的配对字符，一致则生成单词，并作为单词计入，否则计入字符数组
            if ($symbolTemp !== null && in_array($letter,MysqlGrammar::PAIR_SIGN)) {
                if ($letter == $symbolTemp) {
                    $this->processWord($letters,true);
                    $symbolTemp = null;
                }else{
                    array_push($letters,$letter);
                }
                continue;
            }
            //非匹配中的分隔符，生成单词，如果需要记录单词则将符号计入
            if ($symbolTemp === null && in_array($letter,MysqlGrammar::SEPARATE_SIGN)) {
                $this->processWord($letters);
                if (in_array($letter,MysqlGrammar::JOIN_WORD_SIGN))
                    array_push($this->wordList,$letter);
                continue;
            }
            //非匹配中的匹配字符，更新标记，生成单词
            if ($symbolTemp === null && in_array($letter,MysqlGrammar::PAIR_SIGN)) {
                $this->processWord($letters);
                $symbolTemp = $letter;
                continue;
            }
            //普通字符
            array_push($letters,$letter);
        }
        if (!empty($letters))
            $this->processWord($letters);
        if ($symbolTemp !== null)
            throw new MysqlGrammarException(11201,'delete sql syntax error');
    }

    /**
     * 处理单词工具方法，对于order by作为一个单词的特殊处理，处于配对串中的字符串使用''包裹
     * @param &$letters array 字符数组引用
     * @param bool $inPair 单词是否处于引号或单引号之间，如果处于代表肯定不是关键字
     */
    protected function processWord(&$letters,$inPair = false)
    {
        $word = implode("",$letters);
        if (empty($word)) {
            return;
        }
        $upperWord = strtoupper($word);
        //特殊处理order by
        if ($upperWord == 'BY') {
            $lastWord = array_pop($this->wordList);
            if (strtoupper($lastWord) == 'ORDER') {
                $word = $lastWord.' '.$word;
                $upperWord = strtoupper($word);
            }else{
                //放回pop出的末尾单词
                array_push($this->wordList,$lastWord);
            }
        }
        //关键字
        if (in_array($upperWord,self::KEYWORDS) && !$inPair) {
            $this->keywordMap[$upperWord] = count($this->wordList);
        }else{
            if ($inPair)
                $word = '\''.$word.'\'';
            array_push($this->wordList,$word);
        }
        $letters = array();
    }
}