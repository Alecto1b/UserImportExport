<?php

namespace LeconfePlugins\UserImportExport\Pages;

use Filament\Schemas\Schema;

if (class_exists(Schema::class)) {
    class UserImportExportPage extends UserImportExportPageV5 {}
} else {
    class UserImportExportPage extends UserImportExportPageV3 {}
}
