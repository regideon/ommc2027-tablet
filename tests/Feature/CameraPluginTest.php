<?php

use Native\Mobile\Camera;
use Native\Mobile\Facades\Camera as CameraFacade;
use Native\Mobile\PendingMediaPicker;
use Native\Mobile\PendingPhotoCapture;
use Native\Mobile\PendingVideoRecorder;

test('the camera facade resolves through the plugin provider', function () {
    expect(app(Camera::class))->toBeInstanceOf(Camera::class);
    expect(CameraFacade::getFacadeRoot())->toBeInstanceOf(Camera::class);
});

test('the camera facade exposes the native bridge actions', function () {
    expect(CameraFacade::getPhoto())->toBeInstanceOf(PendingPhotoCapture::class);
    expect(CameraFacade::recordVideo())->toBeInstanceOf(PendingVideoRecorder::class);
    expect(CameraFacade::pickImages())->toBeInstanceOf(PendingMediaPicker::class);
});
