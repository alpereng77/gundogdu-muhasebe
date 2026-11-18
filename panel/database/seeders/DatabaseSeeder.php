<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['Muhasebe Müdürü', 'Tüm menüleri görür ve düzenler (kullanıcı yönetimi hariç).', [
                'genel-bakis' => 'view', 'faturalar' => 'edit', 'odemeler' => 'edit', 'tevkifat' => 'edit', 'cek-senet' => 'edit',
                'cari-hesaplar' => 'edit', 'alicilar' => 'edit', 'saticilar' => 'edit', 'hesap-ekstresi' => 'edit', 'ortaklar' => 'edit', 'raporlar' => 'view', 'islem-gecmisi' => 'view', 'ayarlar' => 'edit',
            ]],
            ['Muhasebe Personeli', 'Fatura, ödeme, çek/senet ve cari kaydı girer; ayarlara erişemez.', [
                'genel-bakis' => 'view', 'faturalar' => 'edit', 'odemeler' => 'edit', 'tevkifat' => 'view', 'cek-senet' => 'edit',
                'cari-hesaplar' => 'edit', 'alicilar' => 'edit', 'saticilar' => 'edit', 'hesap-ekstresi' => 'edit', 'ortaklar' => 'view', 'raporlar' => 'view',
            ]],
            ['Şantiye Şefi', 'Yalnızca görüntüleme: özet, faturalar, çek/senet ve raporlar.', [
                'genel-bakis' => 'view', 'faturalar' => 'view', 'cek-senet' => 'view', 'raporlar' => 'view',
            ]],
        ];
        foreach ($roles as [$name, $description, $permissions]) {
            Role::updateOrCreate(['name' => $name], ['description' => $description, 'permissions' => $permissions]);
        }

        $email = strtolower(env('ADMIN_EMAIL', 'admin@gundogdu.test'));
        $password = env('ADMIN_PASSWORD');
        if (! $password) {
            throw new \RuntimeException('.env dosyasında ADMIN_PASSWORD tanımlayınız.');
        }
        User::firstOrCreate(['email' => $email], [
            'name' => env('ADMIN_NAME', 'Süper Admin'), 'password' => $password,
            'is_super_admin' => true, 'all_projects' => true, 'is_active' => true,
        ]);

        $this->call(DemoDataSeeder::class);
    }
}
