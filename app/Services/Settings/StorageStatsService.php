<?php
declare(strict_types=1);
namespace App\Services\Settings;

final class StorageStatsService
{
    public function collect(): array
    {
        $metricsStorage = [
            'table_size' => 0, 'data_size' => 0, 'index_size' => 0,
            'partition_count' => null, 'oldest_partition' => null, 'newest_partition' => null,
            'is_partitioned' => false, 'error' => null,
        ];
        try {
            if (function_exists('metrics_is_partitioned') && metrics_is_partitioned()) {
                $metricsStorage['is_partitioned'] = true;
                $parts = db_all("SELECT PARTITION_NAME AS pname, PARTITION_DESCRIPTION AS pdesc, DATA_LENGTH AS dlen, INDEX_LENGTH AS ilen FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'metrics' AND PARTITION_NAME IS NOT NULL ORDER BY PARTITION_ORDINAL_POSITION ASC");
                $dataSize=0;$indexSize=0;$dates=[];
                foreach ($parts as $p) {
                    $dataSize+=(int)($p['dlen']??0); $indexSize+=(int)($p['ilen']??0);
                    $name = (string)($p['pname']??'');
                    if ($name===''||$name==='pmin'||$name==='pmax') continue;
                    $desc=trim((string)($p['pdesc']??''),"'");
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$desc)) $dates[]=$desc;
                }
                $metricsStorage['data_size']=$dataSize; $metricsStorage['index_size']=$indexSize;
                $metricsStorage['table_size']=$dataSize+$indexSize;
                $metricsStorage['partition_count']=count($dates);
                if ($dates!==[]) {$metricsStorage['oldest_partition']=min($dates); $metricsStorage['newest_partition']=max($dates);}
            } else {
                $table=db_one("SELECT data_length,index_length FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='metrics'");
                if ($table){$metricsStorage['data_size']=(int)$table['data_length']; $metricsStorage['index_size']=(int)$table['index_length']; $metricsStorage['table_size']=$metricsStorage['data_size']+$metricsStorage['index_size'];}
            }
        } catch (\Throwable $e) {$metricsStorage['error']=$e->getMessage();}
        return $metricsStorage;
    }
}
