<?php

namespace App\Filament\Resources\ProductResource\Actions;

use Filament\Actions\Concerns\CanCustomizeProcess;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;

class ResumeBulkAction extends BulkAction
{
    use CanCustomizeProcess;

    public static function getDefaultName(): ?string
    {
        return 'resume';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Resume checking'));

        $this->successNotificationTitle(__('Products resumed'));

        $this->color('gray');

        $this->icon('heroicon-o-play');

        $this->action(function (): void {
            $this->process(static function (Collection $records) {
                $records->each->update(['paused' => false]);
            });

            $this->success();
        });

        $this->deselectRecordsAfterCompletion();
    }
}
