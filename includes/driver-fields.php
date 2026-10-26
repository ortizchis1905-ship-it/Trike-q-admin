<?php
function tqDriverText(array $values,string $key): string { return is_string($values[$key]??null)?trim($values[$key]):''; }
function tqDriverGeography(string $level,string $parent=''): array {
    if (!in_array($level,['provinces','cities','barangays'],true) || ($level!=='provinces'&&!preg_match('/^[0-9]{10}$/',$parent))) throw new RuntimeException('Choose a valid address location.');
    static $directory = null;
    if ($directory === null) {
        $file = __DIR__.'/psgc-addresses.json';
        if (!is_file($file) || !is_readable($file)) throw new RuntimeException('Address data is missing. Put psgc-addresses.json in the includes folder.');
        $directory = json_decode((string)file_get_contents($file), true);
        if (!is_array($directory) || !isset($directory['provinces'],$directory['cities'],$directory['barangays'])) throw new RuntimeException('Address data is invalid. Replace psgc-addresses.json with the complete downloaded file.');
    }
    $rows = $level==='provinces' ? $directory['provinces'] : ($directory[$level][$parent] ?? []);
    if (!$rows || !is_array($rows) || !array_is_list($rows)) throw new RuntimeException('No address locations found for this selection.');
    return $rows;
}
function tqDriverExtraValues(array $post): array {
    $values=[];foreach(['license_number','license_expiry_date','birthdate','province_code','city_code','barangay_code','street'] as $key)$values[$key]=tqDriverText($post,$key);
    $values['license_number']=strtoupper($values['license_number']);
    if(!preg_match('/^[A-Z0-9][A-Z0-9 -]{4,29}$/',$values['license_number']))throw new RuntimeException('Enter a license number using 5–30 letters, digits, spaces or hyphens.');
    $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    foreach(['license_expiry_date','birthdate'] as $key){$date=DateTimeImmutable::createFromFormat('!Y-m-d',$values[$key]);if(!$date||$date->format('Y-m-d')!==$values[$key])throw new RuntimeException('Enter valid license expiry and birthdate dates.');}
    if($values['license_expiry_date']<$today)throw new RuntimeException('The driver license has expired. Register only with a current license.');
    if($values['birthdate']>=$today)throw new RuntimeException('Birthdate must be before today.');
    $names=[];
    foreach([['provinces','','province_code'],['cities',$values['province_code'],'city_code'],['barangays',$values['city_code'],'barangay_code']] as [$level,$parent,$field]) {
        $match=null;foreach(tqDriverGeography($level,$parent) as $row)if($row['code']===$values[$field]){$match=$row['name'];break;}
        if($match===null)throw new RuntimeException('Select a province, municipality/city and barangay from the address lists.');
        $names[]=$match;
    }
    if(strlen($values['street'])>200)throw new RuntimeException('Street must be at most 200 characters.');
    $values['address']=implode(', ',array_filter(array_merge($names,[$values['street']]),fn($v)=>$v!==''));
    return $values;
}
function tqDriverFields(array $values, bool $includeBirthdate = true): void {
    $esc=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    echo '<div class="tq-driver-fields">';
    $labels = ['license_number'=>'Driver’s License Number','license_expiry_date'=>'License Expiry Date'];
    if ($includeBirthdate) $labels['birthdate'] = 'Birthdate';
    foreach($labels as $key=>$label){
        $type=$key==='license_number'?'text':'date';
        $extra=$key==='license_number'?' maxlength="30"':($key==='birthdate'?' max="'.date('Y-m-d',strtotime($today.' -1 day')).'"':' min="'.$today.'"');
        echo '<div><label for="'.$key.'">'.$label.' <span class="required">*</span></label><input id="'.$key.'" name="'.$key.'" type="'.$type.'" value="'.$esc($values[$key]??'').'" required'.$extra.'></div>';
    }
    echo '</div><p class="tq-address-help">TMU must check the driver’s original license. A license photo is not required.</p><fieldset class="tq-address" data-address-picker><legend>Address</legend>';
    foreach(['province_code'=>'Province / Region','city_code'=>'Municipality / City','barangay_code'=>'Barangay'] as $key=>$label){echo '<div><label for="'.$key.'">'.$label.' <span class="required">*</span></label><select id="'.$key.'" name="'.$key.'" data-selected="'.$esc($values[$key]??'').'" required><option value="">Loading…</option></select></div>';}
    echo '<div><label for="street">Street (Optional)</label><input id="street" name="street" maxlength="200" value="'.$esc($values['street']??'').'"></div><p class="tq-address-help" data-address-status role="status" hidden></p><button type="button" data-address-retry hidden>Retry address loading</button></fieldset>';
}
