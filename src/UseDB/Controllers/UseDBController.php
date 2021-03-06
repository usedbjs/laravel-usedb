<?php

namespace UseDB\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UseDBController extends Controller
{
    public $modelClass;

    public function index(Request $request)
    {
        $obj = $request->all();
        $operation = $obj['operation'];
        $this->modelClass = config('usedb.modelPath') . $obj['collection'];

        switch ($operation) {
            case 'create':
                return $this->store($obj);
            case 'findOne':
                return $this->show($obj);
            case 'update':
                return $this->update($obj);
            case 'delete':
                return $this->destroy($obj);
            case 'findMany':
                return $this->findMany($obj);
        }
    }

    public function findMany($obj)
    {
        if (!($this->permission($obj, $this->modelClass, 'findMany') && $this->policies($obj, 'findMany', $this->modelClass))) {
            return response()->json(["error" => "You are not authorized"]);
        }

        $payload = $obj['payload'];
        $errors = [];
        if (!array_key_exists('skip', $payload)) {
            $errors['skip'] = "Skip field in payload is required";
        }
        if (!array_key_exists('take', $payload)) {
            $errors['take'] = "take field in payload is required";
        }
        if (!empty($errors)) {
            return response()->json(['errors' => $errors]);
        }

        $modelCollection = new $this->modelClass();
        if (array_key_exists('where', $payload)) {
            $modelCollection = $this->modelClass::where($payload['where']);
        }
        $total = $modelCollection->count();

        $modelCollection = $modelCollection->skip($payload['skip'])->take($payload['take']);
        $modelCollection = $modelCollection->get();

        $readController = new UseDBReadController();
        $modelCollection = $readController->include($payload, $modelCollection);
        $modelCollection = $readController->select($payload, $modelCollection);

        return response()->json(['data' => $modelCollection, 'pagination' => ['total' => $total]]);
    }

    public function store($obj)
    {
        if (!($this->permission($obj, null, 'create') && $this->policies($obj, 'create', $this->modelClass))) {
            return response()->json(["error" => "You are not authorized"]);
        }

        $payload = $obj['payload'];
        if (!array_key_exists('data', $payload)) {
            return ['error' => 'Data field in payload is required'];
        }

        $data = $payload['data'];
        $model = new $this->modelClass();

        foreach ($data as $prop => $value) {
            $model->$prop = $value;
        }

        if (!$model->save()) {
            return response()->json(['errors' => $model->getErrors()]);
        }
        return response()->json($model);
    }

    public function show($obj)
    {
        $payload = $obj['payload'];
        if (!array_key_exists('where', $payload)) {
            return ['error' => 'where field in payload is required'];
        }

        $where =  $payload['where'];
        $model = $this->modelClass::where($where)->first();
        if (!$model)
            return response()->json(["error" => "Record not found!!"]);

        if (!($this->permission($obj, $model, 'findOne') && $this->policies($obj, 'findOne', $model))) {
            return response()->json(["error" => "You are not authorized"]);
        }

        if (array_key_exists('include', $payload)) {
            $childClasses = $payload['include'];
            $readController = new UseDBReadController();
            $model = $readController->nestedData($childClasses, $model);
            if (array_key_exists('select', $payload)) {
                $select = ['id'];
                $select = array_merge($select, $payload['select']);
                foreach ($payload['include'] as $name => $value) {
                    array_push($select, $name);
                }
                $model = $model->only($select);
            }
        }

        return response()->json($model);
    }

    public function update($obj)
    {
        $payload = $obj['payload'];
        $errors = [];
        if (!array_key_exists('where', $payload)) {
            $errors['where']  = 'where field in payload is required';
        }
        if (!array_key_exists('data', $payload)) {
            $errors['data'] = 'data field is required';
        }
        if (!empty($errors)) {
            return response()->json(['errors' => $errors]);
        }

        $where =  $payload['where'];
        $model = $this->modelClass::where($where)->first();
        if (!$model)
            return response()->json(["error" => "Record not found"]);

        if (!($this->permission($obj, $model, 'update') && $this->policies($obj, 'update', $model))) {
            return response()->json(["error" => "You are not authorized"]);
        }

        $data = $payload['data'];
        foreach ($data as $prop => $value) {
            $model->$prop = $value;
        }

        if (!$model->save()) {
            return response()->json(["errors" => "Not saved"]);
        }
        return response()->json($model);
    }


    public function destroy($obj)
    {
        $payload = $obj['payload'];
        if (!array_key_exists('where', $payload)) {
            return ['error' => 'where field in payload is required'];
        }
        $where =  $payload['where'];

        $model = $this->modelClass::where($where)->first();
        if (!$model)
            return response()->json(["error" => "Record not found"]);

        if (!($this->permission($obj, $model, 'delete') && $this->policies($obj, 'delete', $model))) {
            return response()->json(["error" => "You are not authorized"]);
        }

        $model->delete();
        return response()->json(["message" => "Record deleted successfully"]);
    }

    public function permission($obj, $model, $gate)
    {
        $gates = config('usedb.permissions.gates.' . $obj['collection'] . '.' . $gate);
        if (!$gates) {
            return true;
        }
        foreach ($gates as $gate) {
            if (!Gate::allows($gate, $model)) {
                return false;
            }
        }
        return true;
    }

    public function policies($obj, $name, $model)
    {
        $policy = config('usedb.permissions.policies.' . $obj['collection'] . '.' . $name);
        if ($policy) {
            if (!Gate::allows($policy, $model)) {
                return false;
            }
        }
        return true;
    }
}
