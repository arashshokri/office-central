<?php
namespace Tests\Feature;

use App\Services\PackageService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class RuntimePackageTest extends TestCase
{
    private function zip(array $entries): UploadedFile {
        $path=tempnam(sys_get_temp_dir(),'office-runtime-');
        $zip=new \ZipArchive;$zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE);
        foreach($entries as $name=>$body){$zip->addFromString($name,$body);}
        $zip->close();
        return new UploadedFile($path,'runtime.zip','application/zip',null,true);
    }
    private function entries(): array {
        $manifest=['format'=>'office-runtime-v1','product'=>'office','source_protection'=>'ioncube','version'=>'3.8.19','architecture'=>'amd64','images'=>[]];
        $entries=[];
        foreach(['app','db','redis','rdp-web','rdp-core'] as $role){
            $path='images/'.$role.'.tar';$entries[$path]='fixture-'.$role;
            $manifest['images'][]=['role'=>$role,'archive'=>$path,'ref'=>'fixture/'.$role.':1','image_id'=>'sha256:'.str_repeat('a',64),'sha256'=>hash('sha256',$entries[$path])];
        }
        $entries['manifest.json']=json_encode($manifest);
        return $entries;
    }
    public function test_protected_bundle_manifest_and_all_image_checksums_are_validated():void {
        $file=$this->zip($this->entries());
        try{$result=app(PackageService::class)->inspect($file);$this->assertSame('office-runtime-v1',$result['runtime_manifest']['format']);}
        finally{unlink($file->getRealPath());}
    }
    public function test_raw_source_is_rejected_in_a_runtime_bundle():void {
        $entries=$this->entries();$entries['app/source.php']='<?php';
        $file=$this->zip($entries);
        try{$this->expectException(\InvalidArgumentException::class);app(PackageService::class)->inspect($file);}
        finally{unlink($file->getRealPath());}
    }
    public function test_ready_docker_bundle_does_not_require_commercial_source_encoding():void {
        $entries=$this->entries();$manifest=json_decode($entries['manifest.json'],true);
        $manifest['source_protection']='none';$entries['manifest.json']=json_encode($manifest);
        $file=$this->zip($entries);
        try{$result=app(PackageService::class)->inspect($file);$this->assertSame('none',$result['runtime_manifest']['source_protection']);}
        finally{unlink($file->getRealPath());}
    }
    public function test_changed_image_archive_is_rejected():void {
        $entries=$this->entries();$entries['images/app.tar']='tampered';
        $file=$this->zip($entries);
        try{$this->expectException(\InvalidArgumentException::class);app(PackageService::class)->inspect($file);}
        finally{unlink($file->getRealPath());}
    }
    public function test_archive_traversal_is_rejected():void {
        $file=$this->zip(['../app.php'=>'<?php']);
        try{$this->expectException(\InvalidArgumentException::class);app(PackageService::class)->inspect($file);}
        finally{unlink($file->getRealPath());}
    }
}
