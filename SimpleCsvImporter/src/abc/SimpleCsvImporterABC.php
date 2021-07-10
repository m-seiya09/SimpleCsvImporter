<?php

namespace SimpleCsvImporter\ABC;

use SimpleCsvImporter\Exceptions\CsvColumnException;
use SimpleCsvImporter\Exceptions\PropertyException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use SplFileObject;
use Throwable;

/**
 * 単純な形式のcsvファイルをインポートするための抽象クラス。
 * executeを実行することで、csvのインポート結果を多次元配列にまとめて返却する。
 * このクラスを利用するには、具象クラスで継承し、
 * プロパティのcsvColumnと、modelColumn、encodings、splFileObjectFlagsを定義し、
 * 関数、valueValidationRule、valueValidationMessageを具象クラスで定義する必要ある。
 */
abstract class SimpleCsvImporterABC {

    /* ===== !!! 必須プロパティ(具象クラスで定義してください) !!! ===== */

    /**
     * 可能性のある文字コード
     * @var array
     */
    protected $encodings = [];

    /**
     * csvファイルのカラムを定義
     * @var array
     */
    protected $csvColumn = [];

    /**
     * csvの項目名(csvColumn)のシステム側のデータ名
     * @var array
     */
    protected $modelColumn = [];

    /**
     * SplFileObjectのフラグにセットする設定
     * @var int
     */
    protected $splFileObjectFlags;

    /* ===== プロパティ定義(オーバーライド可) ===== */

    /**
     * エンコード後の文字コードを定義
     * @var string
     */
    protected $encodeType = 'UTF-8';

    /**
     * 対象csvの項目名(column)が存在する行番号
     * @var integer
     */
    protected $csvColumnRowNumber = 1;

    /**
     * 読み込みを許容する行数
     * (注意) あまり大きな値にするとメモリオーバーとなる可能性がある
     * @var integer
     */
    protected $maxCsvRows = 1000;

    /* ===== privateプロパティ定義 ===== */
    
    /**
     * 抽出結果を格納する
     * status：結果ステータスコードが入る
     * invalid： バリデーションでエラーとなった項目が格納される。keyは行番号、valueは配列となる
     * extracts 正常に抽出できた結果を格納する
     * warning： 部分的に結果の抽出に失敗した際にその原因となった行番号をキーに、エラーメッセージをvalueに格納する
     * error： 様々なエラーを格納する。処理中にスローされたエラーが格納されている
     * @var array
     */
    private $result = [
        'status'   => 200,
        'invalid'  => [],
        'extracts' => [],
        'warning'  => [],
        'error'    => [],
    ];

    /**
     * resultのstatusに格納されるステータスコード。
     * success： 何一つ問題なく正常に結果の抽出ができたことを示す
     * column_error； アップロードされたcsvのカラムがプロパティcsvColumnと一致しなかった場合のエラー
     * partially_error：　部分的にエラー。全体的に結果の抽出は行えているが、一部結果の抽出に失敗した箇所が存在する
     * property_error； 必須プロパティの検査でエラーとなったことを示す。エラー内容はresult変数のerrorの項目に格納される
     * problem： 結果抽出の際に致命的なエラーが発生したことを示す。エラー内容はresult変数のerrorの項目に格納される
     */
    private const STATUS_CODE = [
        'success'         => 200,
        'column_error'    => 100,
        'partially_error' => 105,
        'property_error'  => 5,
        'problem'         => 0
    ];

    /* ===== !!! 具象クラスで実装して欲しい関数 !!! ===== */

    /**
     * バリデーションルールを定義する
     * @return array
     */
    abstract protected function valueValidationRule(): array;

    /**
     * バリデーション時のエラーメッセージを定義する
     * @return array
     */
    abstract protected function valueValidationMessage(): array;

    /* ========== 実行関数 ========== */

    /**
     * ファイルインポート処理を実行する。
     * インポートの結果は配列でまとめられる。
     * @param UploadedFile $file
     * @return array|null
     */
    public final function execute(UploadedFile $file): array
    {
        try {
            // 必須プロパティの検査
            $this->propertyValidate();

            $file = new SplFileObject($file->getRealPath());
            $this->setSplFIleObjectFlags($file);

            // csvデータを抽出
            $this->extract($file);

            // 最終結果の判定
            if ($this->result['status'] === self::STATUS_CODE['success']) $this->setFinalResult();

        } catch(Throwable $e) {
            if ($e instanceof PropertyException) $status = self::STATUS_CODE['property_error'];
            elseif ($e instanceof CsvColumnException) $status = self::STATUS_CODE['column_error'];
            else $status = self::STATUS_CODE['problem'];

            Log::error(__METHOD__ . 'error_message' . $e);
            $this->setStatus($status, $e);
        }

        return $this->result;
    }

    /* ===== 関数定義(オーバーライド不可) ===== */

    /**
     * 引数で受け取ったSplFileObjectに設定をセットする
     * @param SplFileObject $file
     * @return void
     */
    private final function setSplFileObjectFlags(SplFileObject &$file): void
    {
        $file->setFlags($this->splFileObjectFlags);
    }

    /**
     * 結果コードの格納と、致命的なエラー内容の格納
     * @param integer $statusCode
     * @param Throwable|null $e
     * @return void
     */
    private final function setStatus(int $statusCode, ?Throwable $e = null)
    {
        $this->result['status'] = $statusCode;

        if ($statusCode === self::STATUS_CODE['property_error']
        || $statusCode === self::STATUS_CODE['problem']
        || $statusCode === self::STATUS_CODE['column_error']) 
        {
            $this->result['error'] = $e;
        }
    }

    /**
     * エラー内容の格納
     * @param string $warningMessage
     * @return void
     */
    private final function setWarning(string $warningMessage): void
    {
        $this->result['warning'][] = $warningMessage;
    }

    /**
     * 引数で受け取った抽出結果をresultのextractsに格納する。
     * 受け取った結果が空の場合、warningに行番号を示したエラーメッセージを格納する
     * @param array $value
     * @param int $index
     * @return void
     */
    private final function setExtracts(array &$value, int $index, bool $isInValid): void
    {
        if (!empty($value)  && !$isInValid) {

            $this->result['extracts'][] = $this->specialProcess($value);
        }
        else {
            $this->result['warning'][] = "{$index}行目の結果の抽出に失敗しました。";
        }
    }

    /**
     * 必須プロパティのcsvColumnとmodelColumnの項目数が一致しているかどうか検査する
     * @return void
     */
    private final function propertyValidate(): void
    {
        if (empty($this->csvColumn)) {
            throw new PropertyException('csvColumnが未定義です');
        } elseif (empty($this->modelColumn)) {
            throw new PropertyException('modelColumnが未定義です');
        } elseif (count($this->csvColumn) !== count($this->modelColumn)) {
            throw new PropertyException('csvColumnのカウント数とmodelColumnのカウント数が一致していません');
        } elseif (empty($this->encodings)) {
            throw new PropertyException('必須プロパティencodingsが定義されていません');
        }
    }

    /**
     * csvカラム(header)の検査を行う。
     * 正常に検査を通過した場合、そのCSVの文字コードが返却される。
     * 検査に失敗した場合、CsvColumnExceptionがスローされる
     * @param array $line
     * @return string|bool
     */
    private final function columnValidate(array &$line): ?string
    {
        $diff = array_diff($line, $this->csvColumn);

        if (count($diff) > 0) throw new CsvColumnException('アップロードされたcsvのカラム数がが期待した値と一致しません');

        $encode_suggest = $this->judgeEncodingType($line);

        if (!$encode_suggest) throw new CsvColumnException('CSVファイルの文字コード読み込みに失敗したか、csvカラムが一部不正な可能性があります');

        return $encode_suggest;
    }

    /**
     * アップロードされたファイルの文字コードが何か決定し、返却する
     * @param array $line
     * @return string|null
     */
    private final function judgeEncodingType(array $line): ?string
    {
        foreach ($this->encodings as $encode_suggest) {
            $header = $line;
            if ($this->encodeType !== $encode_suggest) mb_convert_variables($this->encodeType, $encode_suggest, $header);
            $is_match = true;
            // エンコード後の値と、csvColumnの値を照合して文字コードが一致しているか検査する
            for ($i = 0; $i < count($this->csvColumn); $i++) {
                if ($header[$i] != $this->csvColumn[$i]) $is_match = false;
            }
            // 文字コード決定
            if ($is_match) return $encode_suggest;
        }

        return false;
    }

    /**
     * 抽出データに関してバリデーションを実行する
     * @param array $value
     * @return bool
     */
    private final function valueValidate(array &$value, int $index): bool
    {
        $validator = Validator::make(
            $value,
            $this->valueValidationRule(),
            $this->valueValidationMessage()
        );

        $isInvalid = $validator->fails();
        if ($isInvalid) {
            $this->result['invalid'][$index] = $validator->errors()->all();
        }

        return $isInvalid;
    }

    /**
     * 引数で受け取ったfileオブジェクトをresultプロパティに格納していく
     * @param array $line
     * @return array
     */
    private final function extract(SplFileObject &$file): void
    {
        $conversionType = '';
        // csvファイルのデータを抽出していく
        foreach ($file as $line) {
            // index番号が0から始まるため、正確な行番号を把握するには+1する必要がある
            $key = $file->key() + 1;

            // 中断、カラム検査、スキップ検査
            if ($this->isBreak($line, $key)) {
                $this->setWarning("最大読み込み行数({$this->maxCsvRows})を超えたため、{$key}行目以降の読み込みを中断しました");
                break;
            }
            if ($key === $this->csvColumnRowNumber) $conversionType = $this->columnValidate($line);
            if ($this->isSkipLine($line, $key)) continue;

            // 文字コード変換
            $changed = mb_convert_variables($this->encodeType, $conversionType, $line);

            if ($changed === $conversionType) {
                $extract = $this->combineKeyValue($line);
                $isInValid = $this->valueValidate($extract, $key);
                $this->setExtracts($extract, $key, $isInValid);
            } else {
                $this->setWarning("{$key}行目の文字コード変換に失敗したため、結果の抽出に失敗しました");
            }
        }
    }

    /**
     * 引数で受け取った配列データをプロパティmodelColumnと結合して返却する。
     * 抽出結果は下記の通りになる。
     * key： modelColumn
     * value：csvから抽出したデータ
     * @param array $line
     * @return array
     */
    private final function combineKeyValue(array &$line): array
    {
        $extract = [];
        if (count($this->modelColumn) === count($line)) $extract = array_combine($this->modelColumn, $line);

        return $extract;
    }

    /* ===== 関数定義(オーバーライド可) ===== */

    /**
     * 読み込みをスキップする行か判断する
     * @param array $line
     * @param int $index
     * @return boolean
     */
    protected function isSkipLine(array &$line, int $index): bool
    {
        if ($index === $this->csvColumnRowNumber) return true;
        else return false;
    }

    /**
     * 処理を中断するか判断する
     * @param array $line
     * @param integer $index
     * @return boolean
     */
    protected function isBreak(array &$line, int $index): bool
    {
        if ($index >= $this->maxCsvRows) return true;
        else return false;
    }

    /**
     * 最終結果を確認し、statusコードを格納する。
     * もし最終結果をみて再度エラーをスローしたい場合はここをオーバーライドしてください。
     * @return void
     */
    protected function setFinalResult(): void
    {
        if (empty($this->result['invalid']) && empty($this->result['warning']) && empty($this->result['error'])) {
            $status = self::STATUS_CODE['success'];
        } elseif (count($this->result['warning']) > 0) {
            $status = self::STATUS_CODE['partially_error'];
        } else {
            $status = self::STATUS_CODE['problem'];
        }

        $this->result['status'] = $status;
    }

    /**
     * !!! 抽出したデータに特別な処理を行いたい場合は、この関数をオーバーライドしてください !!!
     * この関数は、setExtractsにて抽出結果をresult変数に格納される前に実行されます。
     * 引数では抽出結果を受け取っており、csv項目には存在しないが、システム側でデータを追加したい場合に役に立ちます。
     * デフォルトでは何も処理せず返却します。
     * @param array $value
     * @return array
     */
    protected function specialProcess(array $value): array
    {
        return $value;
    }
}
