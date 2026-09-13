<?php
declare(strict_types=1);

$base=$argv[1]??'http://127.0.0.1:8080';
function post(string $url,array $body):array {
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>1,CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>1]);
    $raw=curl_exec($ch);if($raw===false)throw new RuntimeException(curl_error($ch));$result=json_decode($raw,true);curl_close($ch);return $result;
}
$order=post($base.'/orders',['customer'=>'race@test','items'=>[['sku'=>'GAME-100'],['sku'=>'GAME-200']]]);
if(!isset($order['id']))throw new RuntimeException(json_encode($order));
$mh=curl_multi_init();$handles=[];$payload=json_encode(['order_id'=>$order['id'],'payment_id'=>'race-'.$order['id'],'status'=>'succeeded']);
for($i=0;$i<20;$i++){$h=curl_init($base.'/webhooks/payment');curl_setopt_array($h,[CURLOPT_POST=>1,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>1]);curl_multi_add_handle($mh,$h);$handles[]=$h;}
do{$status=curl_multi_exec($mh,$running);if($running)curl_multi_select($mh);}while($running&&$status===CURLM_OK);
foreach($handles as $h){curl_multi_remove_handle($mh,$h);curl_close($h);}curl_multi_close($mh);
$final=json_decode((string)file_get_contents($base.'/orders/'.$order['id']),true);
$codes=array_values(array_filter(array_column($final['items']??[],'delivery_code')));
$ok=($final['status']??null)==='fulfilled'&&count($codes)===2&&count(array_unique($codes))===2&&($final['money']['balanced']??false);
echo json_encode(['order_id'=>$order['id'],'status'=>$final['status']??null,'codes'=>$codes,'money'=>$final['money']??null,'passed'=>$ok],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
