<?php

namespace App\Filament\Resources\ProductResource\Actions;

use Filament\Actions\Concerns\CanCustomizeProcess;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;

class PauseBulkAction extends BulkAction
{
    use CanCustomizeProcess;

    public static function getDefaultName(): ?string
    {
        return 'pause';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Pause checking'));

        $this->successNotificationTitle(__('Products paused'));

        $this->color('gray');

        $this->icon('heroicon-o-pause');

        $this->action(function (): void {
            $this->process(static function (Collection $records) {
                $records->each->update(['paused' => true]);
            });

            $this->success();
        });

        $this->deselectRecordsAfterCompletion();
    }
}
