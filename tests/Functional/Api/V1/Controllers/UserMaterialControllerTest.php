<?php

namespace App\Functional\Api\V1\Controllers;

use App\TestCase;
use App\User;
use App\Models\Material;
use App\Models\UserMaterial;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class UserMaterialControllerTest extends TestCase
{
    use DatabaseMigrations;

    protected $owner;
    protected $material;
    protected $inventory;
    protected $headers;

    public function setUp()
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Studio owner',
            'email' => 'studio@example.com',
            'password' => '123456'
        ]);
        $this->material = new Material();
        $this->material->name = 'Pantry silica';
        $this->material->description = '';
        $this->material->is_primitive = true;
        $this->material->created_by_user_id = $this->owner->id;
        $this->material->save();
        $this->inventory = new UserMaterial();
        $this->inventory->user_id = $this->owner->id;
        $this->inventory->material_id = $this->material->id;
        $this->inventory->save();
        $this->headers = ['Authorization' => 'Bearer ' . JWTAuth::fromUser($this->owner)];
    }

    public function testQuantityPersistsThroughFractionalZeroAndUntrackedStates()
    {
        foreach ([[12.375, 'kg'], ['.125', 'oz'], [0, 'g'], [null, null]] as $quantity) {
            $this->patchQuantity($quantity[0], $quantity[1])->assertStatus(200)->assertJson([
                'data' => ['stockAmount' => $quantity[0], 'stockUnit' => $quantity[1]]
            ]);
            $stored = $this->inventory->fresh();
            $this->assertEquals($quantity[0], $stored->stock_amount);
            $this->assertSame($quantity[1], $stored->stock_unit);
            if ($quantity[0] === null) {
                $this->assertNull($stored->stock_amount);
            }
            $this->getJson('api/usermaterials', $this->headers)->assertStatus(200)->assertJson([
                'data' => [[
                    'id' => $this->inventory->id,
                    'stockAmount' => $quantity[0],
                    'stockUnit' => $quantity[1]
                ]]
            ]);
        }
    }

    public function testNewInventoryIsUntrackedAndAddingAgainPreservesItsQuantity()
    {
        $this->inventory->delete();
        $url = 'api/usermaterials/addMaterial/' . $this->material->id;
        $response = $this->getJson($url, $this->headers);
        $response->assertStatus(200)->assertJson([
            'data' => ['stockAmount' => null, 'stockUnit' => null]
        ]);
        $data = json_decode($response->getContent(), true)['data'];
        $this->inventory = UserMaterial::findOrFail($data['id']);
        $this->assertNull($this->inventory->stock_amount);
        $this->assertNull($this->inventory->stock_unit);
        $this->patchQuantity(2.5, 'lb')->assertStatus(200);
        $this->getJson($url, $this->headers)->assertStatus(200)->assertJson([
            'data' => ['id' => $this->inventory->id, 'stockAmount' => 2.5, 'stockUnit' => 'lb']
        ]);
    }

    public function testMaximumQuantityAndExplicitUnitChangesDoNotConvertAmounts()
    {
        $this->patchQuantity('9999999999.999', 'oz')->assertStatus(200);
        $this->assertSame('9999999999.999', $this->inventory->fresh()->stock_amount);
        $this->patchQuantity('9999999999.999', 'kg')->assertStatus(200);
        $stored = $this->inventory->fresh();
        $this->assertSame('9999999999.999', $stored->stock_amount);
        $this->assertSame('kg', $stored->stock_unit);
    }

    /**
     * @dataProvider invalidQuantities
     */
    public function testInvalidQuantityDoesNotOverwriteSavedStock($payload)
    {
        $this->inventory->update(['stock_amount' => 5.125, 'stock_unit' => 'kg']);
        $this->patchJson('api/usermaterials/' . $this->inventory->id, $payload, $this->headers)
            ->assertStatus(422)->assertJson(['error' => ['status_code' => 422]]);
        $stored = $this->inventory->fresh();
        $this->assertSame('5.125', $stored->stock_amount);
        $this->assertSame('kg', $stored->stock_unit);
    }

    public function invalidQuantities()
    {
        return [
            'negative' => [['stock_amount' => -1, 'stock_unit' => 'g']],
            'precision' => [['stock_amount' => '1.0001', 'stock_unit' => 'g']],
            'overflow' => [['stock_amount' => '10000000000', 'stock_unit' => 'g']],
            'unsupported unit' => [['stock_amount' => 1, 'stock_unit' => 'ml']],
            'missing unit' => [['stock_amount' => 1]],
            'missing amount' => [['stock_unit' => 'g']],
            'null unit with amount' => [['stock_amount' => 1, 'stock_unit' => null]],
            'null amount with unit' => [['stock_amount' => null, 'stock_unit' => 'g']],
            'incomplete clear amount' => [['stock_amount' => null]],
            'incomplete clear unit' => [['stock_unit' => null]],
            'empty strings are not clear' => [['stock_amount' => '', 'stock_unit' => '']],
            'boolean amount' => [['stock_amount' => true, 'stock_unit' => 'g']],
            'not numeric' => [['stock_amount' => 'plenty', 'stock_unit' => 'g']]
        ];
    }

    public function testOtherInventoryFieldsRemainEditableWithoutChangingStockOrOwnership()
    {
        // Historical rows may have an amount but no known unit.
        $this->inventory->update(['stock_amount' => 7, 'stock_unit' => null]);
        $this->patchJson('api/usermaterials/' . $this->inventory->id, [
            'price' => 4.50,
            'vendor' => 'Local supplier',
            'vendor_code' => 'SIL-1',
            'user_id' => 999999,
            'material_id' => 999999
        ], $this->headers)->assertStatus(200);
        $stored = $this->inventory->fresh();
        $this->assertSame('7.000', $stored->stock_amount);
        $this->assertNull($stored->stock_unit);
        $this->assertEquals(4.50, $stored->price);
        $this->assertSame('Local supplier', $stored->vendor);
        $this->assertSame('SIL-1', $stored->vendor_code);
        $this->assertEquals($this->owner->id, $stored->user_id);
        $this->assertEquals($this->material->id, $stored->material_id);
        $this->patchQuantity(7, 'g')->assertStatus(200);
        $this->assertSame('g', $this->inventory->fresh()->stock_unit);
    }

    public function testOtherUsersCannotUpdateStockEvenWithInvalidInput()
    {
        $other = User::create([
            'name' => 'Other studio',
            'email' => 'other@example.com',
            'password' => '123456'
        ]);
        $headers = ['Authorization' => 'Bearer ' . JWTAuth::fromUser($other)];
        foreach ([['stock_amount' => 2, 'stock_unit' => 'g'], ['stock_amount' => -1]] as $payload) {
            $this->patchJson('api/usermaterials/' . $this->inventory->id, $payload, $headers)
                ->assertJson(['error' => ['status_code' => 401]]);
            $this->assertNull($this->inventory->fresh()->stock_amount);
            $this->assertNull($this->inventory->fresh()->stock_unit);
        }
    }

    public function testUnauthenticatedUsersCannotUpdateStock()
    {
        $this->patchJson('api/usermaterials/' . $this->inventory->id, [
            'stock_amount' => 2, 'stock_unit' => 'g'
        ])->assertStatus(401);
        $this->assertNull($this->inventory->fresh()->stock_amount);
        $this->assertNull($this->inventory->fresh()->stock_unit);
    }

    public function testMigrationPreservesLegacyAmountsAndRollbackHandlesNewQuantityStates()
    {
        $migration = new \AddStockUnitsToUserMaterials();
        $migration->down();
        DB::table('user_materials')->where('id', $this->inventory->id)->update([
            'stock_amount' => 4294967295
        ]);
        $zeroId = DB::table('user_materials')->insertGetId([
            'user_id' => $this->owner->id,
            'material_id' => $this->material->id,
            'stock_amount' => 0
        ]);
        $migration->up();
        $this->assertSame('4294967295.000', $this->inventory->fresh()->stock_amount);
        $this->assertNull($this->inventory->fresh()->stock_unit);
        $this->assertSame('0.000', UserMaterial::findOrFail($zeroId)->stock_amount);
        $this->assertNull(UserMaterial::findOrFail($zeroId)->stock_unit);

        $untrackedId = DB::table('user_materials')->insertGetId([
            'user_id' => $this->owner->id,
            'material_id' => $this->material->id
        ]);
        $this->assertNull(UserMaterial::findOrFail($untrackedId)->stock_amount);
        $this->assertNull(UserMaterial::findOrFail($untrackedId)->stock_unit);
        $this->inventory->update(['stock_amount' => '9999999999.999', 'stock_unit' => 'g']);
        UserMaterial::findOrFail($zeroId)->update(['stock_amount' => '12.375', 'stock_unit' => 'kg']);
        $migration->down();
        $this->assertEquals(4294967295, DB::table('user_materials')->where('id', $this->inventory->id)->value('stock_amount'));
        $this->assertEquals(12, DB::table('user_materials')->where('id', $zeroId)->value('stock_amount'));
        $this->assertEquals(0, DB::table('user_materials')->where('id', $untrackedId)->value('stock_amount'));
        $migration->up();
    }

    protected function patchQuantity($amount, $unit)
    {
        return $this->patchJson('api/usermaterials/' . $this->inventory->id, [
            'stock_amount' => $amount,
            'stock_unit' => $unit
        ], $this->headers);
    }
}
