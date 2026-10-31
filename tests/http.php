<?php
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$host = $argv[2] ?? '';
$cases=[['GET','index.php?fc=module&module=lunixipay&controller=webhook&shop_id=1',null,405],['POST','index.php?fc=module&module=lunixipay&controller=webhook&shop_id=1','{}',400],['GET','index.php?fc=module&module=lunixipay&controller=checkout&id_order=1',null,302]];
foreach($cases as[$method,$path,$body,$expected]){$c=curl_init($base.'/'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>array_filter(['Content-Type: application/json', $host !== '' ? 'Host: '.$host : ''])]);if($body!==null){curl_setopt($c,CURLOPT_POSTFIELDS,$body);}$result=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);if($status!==$expected){echo "HTTP route failed: method=$method expected=$expected actual=$status\n";exit(1);}if(str_contains((string)$result,'native_checkout'))exit(2);curl_close($c);}
echo "Native HTTP routes: method/signature rejection and unauthenticated checkout redirect passed.\n";
