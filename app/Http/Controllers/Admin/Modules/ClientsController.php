<?php

namespace App\Http\Controllers\Admin\Modules;

use App\Services\Auth\AuthService;
use Echo\Framework\Admin\Schema\{FormSchemaBuilder, TableSchemaBuilder};
use Echo\Framework\Http\ModuleController;
use Echo\Framework\Routing\Group;

#[Group(pathPrefix: "/clients", namePrefix: "clients")]
class ClientsController extends ModuleController
{
    protected string $tableName = "clients";

    protected function defineTable(TableSchemaBuilder $builder): void
    {
        $builder->defaultSort('id', 'DESC');

        $builder->column('id', 'ID');
        $builder->column('uuid', 'UUID');
        $builder->column('username', 'Username')
                ->searchable();
        $builder->column('created_at', 'Created');

        $builder->rowAction('show');
        $builder->rowAction('edit');
        $builder->rowAction('delete');

        $builder->toolbarAction('create');
        $builder->toolbarAction('export');

        $builder->bulkAction('delete', 'Delete');
    }

    protected function defineForm(FormSchemaBuilder $builder): void
    {
        $builder->field('avatar', 'Avatar')
                ->image()
                ->accept('image/*');

        $builder->field('username', 'Username')
                ->input()
                ->rules(['required', 'min_length:3', 'unique:clients']);

        $builder->field('password', 'Password', "'' as password")
                ->password()
                ->requiredOnCreate()
                ->rules(['required', 'min_length:4']);

        $builder->field('password_match', 'Password (again)', "'' as password_match")
                ->password()
                ->requiredOnCreate()
                ->rules(['required', 'match:password']);
    }

    public function validate(array $ruleset = [], mixed $id = null): mixed
    {
        if ($id) {
            $ruleset = $this->removeValidationRule($ruleset, "username", "unique:clients");
        }
        $this->setValidationMessage("password.min_length", "Must be at least 4 characters");
        $this->setValidationMessage("password_match.match", "Password does not match");
        return parent::validate($ruleset);
    }

    protected function handleStore(array $request): mixed
    {
        $service = container()->get(AuthService::class);
        unset($request["password_match"]);
        $request["password"] = $service->hashPassword($request['password']);
        return parent::handleStore($request);
    }

    protected function handleUpdate(int $id, array $request): bool
    {
        unset($request["password_match"]);
        if (!empty($request["password"])) {
            $service = container()->get(AuthService::class);
            $request["password"] = $service->hashPassword($request['password']);
        } else {
            unset($request["password"]);
        }
        return parent::handleUpdate($id, $request);
    }
}
