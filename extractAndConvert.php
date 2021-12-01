#!/usr/bin/php
<?php

if (php_sapi_name() !== 'cli') {
    exit;
}

function parseTime (string $timeStr): string
{
    $exploded = explode(':', $timeStr);
    $lastKey = array_key_last($exploded);
    $exploded[$lastKey] = str_replace('.', ',', $exploded[$lastKey]).'0';
    $exploded[0] = '0'.$exploded[0];

    return implode(':', $exploded);
}

$DS = DIRECTORY_SEPARATOR;

$filePath = $argv[1];
$fileName = basename($filePath);
$regex = "/^(.*)\.mkv$/";

// test the file name
if (!preg_match($regex, $fileName, $matches)) {
    fwrite(STDOUT, 'Only working with mkv file');
    exit();
}
$fileNameWithoutExtension = $matches[1];
$dirName = dirname($filePath);

if( !file_exists($filePath)) {
    echo 'File does not exist !';
    exit;
}

// Get mkv infos
$mkvInfos = shell_exec('mkvmerge -J --flush-on-close "'.$filePath.'"');
$jsonDecodedInfos = json_decode($mkvInfos, true);

//Parse infos
$useFullTracksInfos = [];
foreach($jsonDecodedInfos['tracks'] as $curTrack) {
    if ($curTrack['type'] === 'subtitles') {
        $useFullTracksInfos[] = [
            'id' => $curTrack['id'],
            'type' => $curTrack['properties']['codec_id'],
            'language' => $curTrack['properties']['language'],
            'language_ietf' => $curTrack['properties']['language_ietf'],
            'track_name' => $curTrack['properties']['track_name']
        ];
    }
}

// Prompts the user
fwrite(STDOUT, 'Choose one or multiple subtitles to extract' . PHP_EOL);
fwrite(STDOUT, 'if multiple, use a comma (,) to separate' . PHP_EOL);
fwrite(STDOUT, 'Choices : ' . PHP_EOL);
$format = '|%-5s |%-15s |%-5s'.PHP_EOL;
fwrite(STDOUT, sprintf($format, 'id', 'Codec type', 'language'));
foreach($useFullTracksInfos as $curInfos) {
fwrite(STDOUT, sprintf($format, $curInfos['id'], $curInfos['type'], $curInfos['track_name'].' - '.$curInfos['language']. ' - '.$curInfos['language_ietf']));
}
$ids = rtrim(fgets(STDIN));

//Parse ids and extract
$extractedPaths = [];
$extractCommand = 'mkvextract "'.$filePath.'" tracks';
foreach(explode(',',$ids) as $id) {
    $curTrackInfosKey = array_search($id, array_column($useFullTracksInfos, 'id'));
    $curTrackInfos = $useFullTracksInfos[$curTrackInfosKey];
    $subtitleExt = 'ass';
    $subtitleFileName = $fileNameWithoutExtension.'.'.$curTrackInfos['language_ietf'];
    $extractResPath = $dirName.$DS.$subtitleFileName.'.'.$subtitleExt;
    $finalPath = strpos($extractResPath, ' ') !== false ? '"'.$extractResPath.'"' : $extractResPath;

    $extractCommand .= ' '.$id.':'.$finalPath;
    $extractedPaths[] = ['filePath' => $extractResPath, 'filename' => $subtitleFileName];
}

$extractResult = system($extractCommand);
if ($extractResult === false) {
    fwrite(STDOUT, 'Error when extracting'.PHP_EOL.'Executed command :"'.$extractCommand.'"');
    exit();
}

foreach($extractedPaths as $path) {
    $subtitleFile = fopen($path['filePath'], 'r');
    $srtFile = fopen($dirName.$DS.$path['filename'].'.srt', 'w');

    $line = fgets($subtitleFile);
    while( $line !== '[Events]'){
        $line = trim(fgets($subtitleFile));
    }
    $formatsLine = fgets($subtitleFile);
    $formats = explode(',', trim(str_replace('Format: ', '', $formatsLine)));
    $formatsNbr = count($formats);

    $line = fgets($subtitleFile);
    $subtitleNbr = 1;
    while($line !== '' && !feof($subtitleFile)) {
        $dialogInfos = explode(',', str_replace('Dialogue: ', '', $line), $formatsNbr);
        $combinedInformations = array_combine($formats, $dialogInfos);

        $text = preg_replace('/\{\\\\\w*\}/', '', trim($combinedInformations['Text']));
        $lineBreakParsed = explode('\N', $text);
        $startTime = parseTime($combinedInformations['Start']);
        $endTime = parseTime($combinedInformations['End']);


        fwrite($srtFile, $subtitleNbr.PHP_EOL);
        fwrite($srtFile, $startTime.' --> '.$endTime.PHP_EOL);
        foreach ($lineBreakParsed as $curLine) {
            fwrite($srtFile, $curLine.PHP_EOL);
        }
        fwrite($srtFile, PHP_EOL);

        $line = fgets($subtitleFile);
        $subtitleNbr++;
    }

    fclose($subtitleFile);
    fclose($srtFile);
}

exit;