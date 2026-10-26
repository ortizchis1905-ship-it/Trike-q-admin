<?php
function tqValidateDriverPhoto($file) {
 if (!$file || ($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return null;
 if (($file['error']??-1)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']??'')) throw new RuntimeException('Profile photo upload failed. Choose the file again.');
 if (($file['size']??0)<=0 || $file['size']>5*1024*1024) throw new RuntimeException('Profile photo must be at most 5 MB.');
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
 if (!in_array($mime,['image/jpeg','image/png','image/webp'],true) || !getimagesize($file['tmp_name'])) throw new RuntimeException('Choose a valid JPG, PNG or WEBP photo.');
 return ['file'=>$file['tmp_name'],'mime'=>$mime];
}
function tqSaveDriverPhoto($id,$photo) {
 global $SUPABASE_URL,$SUPABASE_KEY;
 $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
 $path=$id.'/profile-'.bin2hex(random_bytes(12)).'.'.$extensions[$photo['mime']];
 $url=rtrim($SUPABASE_URL,'/').'/storage/v1/object/driver-profiles/'.$path;
 $ch=curl_init($url);
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>file_get_contents($photo['file']),CURLOPT_HTTPHEADER=>['apikey: '.$SUPABASE_KEY,'Authorization: Bearer '.$_SESSION['access_token'],'Content-Type: '.$photo['mime']],CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30]);
 $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 if($response===false||$status<200||$status>=300)throw new RuntimeException('Driver registered, but photo storage failed. Add the photo through Edit Driver.');
 $public=rtrim($SUPABASE_URL,'/').'/storage/v1/object/public/driver-profiles/'.$path;
 $saved=supabaseRequest('drivers','PATCH',['profile_picture_url'=>$public],'id=eq.'.urlencode($id));
 if(empty($saved['success'])||($saved['data'][0]['id']??'')!==$id)throw new RuntimeException('Driver registered, but photo could not be linked. Add it through Edit Driver.');
}
