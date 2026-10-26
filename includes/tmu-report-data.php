<?php
// Shared read-only data for the TMU Dashboard and Reports pages.
if (!defined('TRIKEQ_REPORT_CONTEXT')) { http_response_code(404); exit; }
function tqReportDataLoad() {
    $sources = [
        'drivers'=>'select=*&order=name.asc,id.asc',
        'toda_organizations'=>'select=id,name&order=name.asc,id.asc',
        'dues'=>'select=*&order=collection_date.desc,id.asc',
        'complaints'=>'select=*&order=created_at.desc,id.asc',
    ];
    $data=['ready'=>[],'errors'=>[]];
    foreach($sources as $table=>$query) {
        $r=tqFetchAll($table,$query);
        $ok=in_array((int)($r['status']??0),[200,206],true) && is_array($r['data']??null) && empty($r['error']);
        $data[$table]=$ok?$r['data']:[];
        $data['ready'][$table]=$ok;
        if(!$ok)$data['errors'][]='Unable to load '.str_replace('_',' ',$table).'.';
    }
    $data['toda_names']=[];$data['driver_names']=[];
    foreach($data['toda_organizations'] as $t)$data['toda_names'][$t['id']]=$t['name'];
    foreach($data['drivers'] as $d)$data['driver_names'][$d['id']]=$d['name']??'Unnamed driver';
    return $data;
}
function tqReportDate($value) {
    if(!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$value))return null;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Asia/Manila'));
    return $date && $date->format('Y-m-d')===$value?$date:null;
}
function tqReportDay($value,$plainDate=false) {
    if(!is_string($value) || trim($value)==='')return '';
    if($plainDate){$date=tqReportDate($value);return $date?$date->format('Y-m-d'):'';}
    try{return (new DateTimeImmutable($value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d');}catch(Throwable $e){return '';}
}
function tqReportInPeriod($value,$from,$to,$plainDate=false) {
    if($from==='' && $to==='')return true;
    $day=tqReportDay($value,$plainDate);
    return $day!=='' && ($from==='' || $day>=$from) && ($to==='' || $day<=$to);
}
function tqReportRenewal($value) {
    $date=tqReportDate($value);if(!$date)return 'No expiry date';
    $today=new DateTimeImmutable('today',new DateTimeZone('Asia/Manila'));
    $days=(int)$today->diff($date)->format('%r%a');
    return $days<0?'Expired':($days<=30?'Due Soon':'Valid');
}
function tqReportComplaintStatus($value) {
    $value=trim((string)$value);
    return in_array($value,['Resolved','Dismissed'],true)?$value:'Pending';
}
function tqReportOverview($data) {
    $s=['drivers'=>count($data['drivers']),'organizations'=>count($data['toda_organizations']),
        'complaints'=>count($data['complaints']),'dues'=>count($data['dues']),'collections'=>0,
        'active'=>0,'inactive'=>0,'other_driver_status'=>0,
        'renewals'=>['Valid'=>0,'Due Soon'=>0,'Expired'=>0,'No expiry date'=>0],
        'complaint_status'=>['Pending'=>0,'Resolved'=>0,'Dismissed'=>0],
        'driver_toda'=>[],'collection_toda'=>[],'monthly'=>[],'complaint_types'=>[]];
    foreach($data['toda_organizations'] as $t){$s['driver_toda'][$t['id']]=['name'=>$t['name'],'value'=>0];$s['collection_toda'][$t['id']]=['name'=>$t['name'],'value'=>0];}
    foreach($data['drivers'] as $d){
        $status=strtolower(trim((string)($d['status']??'')));
        if($status==='active')$s['active']++;elseif($status==='inactive')$s['inactive']++;else $s['other_driver_status']++;
        $key=isset($data['toda_names'][$d['toda_id']??''])?$d['toda_id']:'unassigned';
        if(!isset($s['driver_toda'][$key]))$s['driver_toda'][$key]=['name'=>'Unassigned / unavailable','value'=>0];
        $s['driver_toda'][$key]['value']++;
        $s['renewals'][tqReportRenewal($d['franchise_expiry_date']??null)]++;
    }
    foreach($data['dues'] as $d){
        $amount=(float)($d['amount']??0);$s['collections']+=$amount;
        $key=isset($data['toda_names'][$d['toda_id']??''])?$d['toda_id']:'unassigned';
        if(!isset($s['collection_toda'][$key]))$s['collection_toda'][$key]=['name'=>'Unassigned / unavailable','value'=>0];
        $s['collection_toda'][$key]['value']+=$amount;
        $date=tqReportDate($d['collection_date']??null);
        if($date){$month=$date->format('Y-m');if(!isset($s['monthly'][$month]))$s['monthly'][$month]=['name'=>$date->format('M Y'),'value'=>0];$s['monthly'][$month]['value']+=$amount;}
    }
    ksort($s['monthly']);
    foreach($data['complaints'] as $c){
        $s['complaint_status'][tqReportComplaintStatus($c['status']??'Pending')]++;
        $types=is_array($c['complaint_types']??null)&&$c['complaint_types']?$c['complaint_types']:[$c['complaint_type']??'Unspecified'];
        $seen=[];foreach($types as $type){$type=trim((string)$type);if($type==='')$type='Unspecified';$key=strtolower($type);if(isset($seen[$key]))continue;$seen[$key]=true;$s['complaint_types'][$type]=($s['complaint_types'][$type]??0)+1;}
    }
    arsort($s['complaint_types']);
    return $s;
}
