<?php

namespace LeconfePlugins\UserImportExport\Pages;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use App\Models\User;

class UserImportExportPageV3 extends AbstractUserImportExportPage implements HasForms, HasTable
{
    use InteractsWithForms; use InteractsWithTable;
    protected static string $view = 'UserImportExport::pages.user-import-export';
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';
    public function makeImportForm(Form $form): Form { return $form->schema([$this->fileField()])->statePath('importData'); }
    protected function fileField(): FileUpload { return FileUpload::make('file')->label('YAML file')->acceptedFileTypes(['application/x-yaml','application/yaml','text/x-yaml','text/yaml','text/plain','application/octet-stream'])->helperText('Upload a UTF-8 YAML file.')->maxSize(config('media-library.max_file_size') / 1024)->rules(['mimes:yaml,yml'])->storeFiles(false)->required(); }
    public function table(Table $table): Table
    {
        return $table->query($this->exportUsersQuery())->columns($this->tableColumns())->filters([$this->roleFilter()])
            ->headerActions([$this->exportAllAction()])->bulkActions([$this->exportSelectedAction()])
            ->checkIfRecordIsSelectableUsing(fn (User $record): bool => filled($record->email));
    }
    protected function tableColumns(): array { return [TextColumn::make('full_name')->label('User')->searchable(query: fn (Builder $q, string $s): Builder => $q->where('given_name','like',"%{$s}%")->orWhere('family_name','like',"%{$s}%")), TextColumn::make('email')->searchable()->sortable(), TextColumn::make('roles.name')->label('Roles')->badge()]; }
    protected function roleFilter(): SelectFilter { return SelectFilter::make('role')->label('Role')->multiple()->options($this->roleOptions())->query(fn (Builder $q, array $data): Builder => ($data['values'] ?? []) === [] ? $q : $q->whereHas('roles', fn ($roles) => $this->scheduledConferenceRoles($roles)->whereIn('roles.id', $data['values']))); }
    protected function exportAllAction(): Action { return Action::make('exportAll')->label('Export all users')->icon('heroicon-o-arrow-down-tray')->authorize(fn (): bool => static::canAccess())->action(fn () => $this->exportAllUsers()); }
    protected function exportSelectedAction(): BulkAction { return BulkAction::make('exportSelected')->label('Export selected users')->icon('heroicon-o-arrow-down-tray')->authorize(fn (): bool => static::canAccess())->action(fn (Collection $records) => $this->exportSelectedUsers($records)); }
}
