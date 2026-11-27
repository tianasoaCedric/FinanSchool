<?php

namespace App\Http\Controllers\PlanCompte;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PlanCompte\Classe;
use App\Services\Comptabilite\PlanComptableGuard;

class ClasseController extends Controller
{
    public function index()
    {
        return Classe::all();
    }

    public function show($id)
    {
        return Classe::findOrFail($id);
    }

    public function store(Request $request)
    {
        $request->validate([
            'Code' => ['required', 'regex:/^\d$/', 'unique:classes,Code'],
            'Libelle' => 'required|string|max:255',
        ]);

        return Classe::create($request->only(['Code', 'Libelle']));
    }

    public function update(Request $request, $id)
    {
        $classe = Classe::findOrFail($id);
        $data = $request->validate([
            'Code' => ['required', 'regex:/^\d$/', "unique:classes,Code,{$id},Id_Classe"],
            'Libelle' => 'required|string|max:255',
        ], ['Code.regex' => 'Le code d\'une classe est un chiffre (1 à 9).']);
        PlanComptableGuard::assertCodeClasseModifiable($classe, $data['Code']);
        $classe->update($data);

        return $classe;
    }

    public function destroy($id)
    {
        $classe = Classe::findOrFail($id);
        PlanComptableGuard::assertClasseSupprimable($classe);
        $classe->delete();

        return response()->json(['message' => 'Classe supprimée']);
    }
}
