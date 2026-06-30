<?php

declare(strict_types=1);

namespace App\Services\MasterData;

use Illuminate\Support\Facades\DB;

class CardsStatService
{
    /**
     * @return array<int, array{id_institution:string, code:string|null, name:string|null, tot_cards:int}>
     */
    public function getCardsStat(): array
    {
        $institutions = DB::select('SELECT id as id_institution, code, name FROM iartnet_master.institutions');

        $result = [];
        foreach ($institutions as $institution) {
            $idInstitution = (string) ($institution->id_institution ?? '');
            if ($idInstitution === '') {
                continue;
            }

            $row = DB::selectOne(
                'SELECT count(a.id) as tot_cards FROM iartnet_master.records a where primary_institution_id = ?',
                [$idInstitution]
            );

            $result[] = [
                'id_institution' => $idInstitution,
                'code' => $institution->code ?? null,
                'name' => $institution->name ?? null,
                'tot_cards' => (int) ($row->tot_cards ?? 0),
            ];
        }

        return $result;
    }
}

