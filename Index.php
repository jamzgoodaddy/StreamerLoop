<?php
$uploadDir = "uploads/";
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$processesFile = "processes.json";
$processes = file_exists($processesFile) ? json_decode(file_get_contents($processesFile), true) : [];

if ($_GET['action'] === 'get_uploaded_files') {
    $type = $_GET['type'] ?? 'video';
    $extensions = $type === 'audio' ? ['mp3', 'aac', 'wav', 'ogg','MP3'] : ['mp4', 'mkv', 'avi', 'mov'];
    $uploadedFiles = array_filter(scandir($uploadDir), function($file) use ($extensions) {
        return in_array(pathinfo($file, PATHINFO_EXTENSION), $extensions);
    });
    echo json_encode(array_values($uploadedFiles));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
// Updated logic for starting the stream
    if ($action === 'start') {
        $streamKey = htmlspecialchars($_POST['stream_key']);
        $videoSource = $_POST['video_source'];
        $audioSource = $_POST['audio_source'];

        $videoFile = ($videoSource === 'computer') 
            ? moveUploadedFile('input_video') 
            : $uploadDir . $_POST['uploaded_video'];

        $audioFiles = [];
        if ($audioSource === 'computer') {
            foreach ($_FILES['audio_files']['tmp_name'] as $index => $tmpName) {
                $audioFiles[] = moveUploadedFile("audio_files", $index);
            }
        } elseif ($audioSource === 'uploaded') {
            foreach ($_POST['uploaded_audio'] as $audio) {
                $audioFiles[] = $uploadDir . $audio;
            }
        }
       

        // Generate FFmpeg command
        $audioInputs = '';
            $mapOptions = '';
            $inputIndex = 0;
            // Construct ffmpeg command

            $audioInputs .= "-stream_loop -1 -re -i '$videoFile' ";
            $mapOptions .= "-map $inputIndex:v:0 ";
            $inputIndex++;

            if (count($audioFiles) > 1) {
            // Combine multiple audio streams
            $audioMixInputs = '';
            foreach ($audioFiles as $audioFile) {
                $audioInputs .= "-stream_loop -1 -re -i '$audioFile' ";
                $audioMixInputs .= "[$inputIndex:a:0]";
                $inputIndex++;
            }

            // Create amix filter
            $filterComplex = "-filter_complex \"$audioMixInputs amix=inputs=" . count($audioFiles) . "[aout]\"";
            $mapOptions .= "-map \"[aout]\" ";
        } elseif (count($audioFiles) === 1) {
            // Single audio file
            $audioInputs .= "-stream_loop -1 -re -i '{$audioFiles[0]}' ";
            $mapOptions .= "-map $inputIndex:a:0 ";
            $inputIndex++;
        } 
            
            // Output streaming URL (example for RTMP server)
            $rtmpUrl = "rtmp://x.rtmp.youtube.com/live2/$streamKey";

            $command = "ffmpeg $audioInputs $filterComplex $mapOptions -c:v copy -f flv $rtmpUrl > /dev/null 2>&1 & echo $!";
            $pid = trim(shell_exec($command));

        if ($pid) {
             $processes[] = [
            "file" => $videoFile,
            "audio_files" => $audioFiles,
            "stream_key" => $streamKey,
            "pid" => $pid,
            "status" => "Running",
            "start_time" => time(),
        ];
            file_put_contents($processesFile, json_encode($processes));
        } else {
            echo "Error: Could not start the streaming process.";
        }
    } elseif ($action === "stop" && isset($_POST['pid'])) {
        $pid = intval($_POST['pid']);
        
        // Use a 'for' loop to iterate and remove the stopped process
        for ($i = 0; $i < count($processes); $i++) {
            if ($processes[$i]['pid'] == $pid) {
                // Stop the process
                exec("kill $pid", $output, $returnVar);

                if ($returnVar === 0) {
                    // Remove the process from the array
                    array_splice($processes, $i, 1);
                    file_put_contents($processesFile, json_encode($processes));
                    break;
                } else {
                    echo "Error: Could not stop the process.";
                }
            }
        }
    }


  

}

function isProcessRunning($pid)
{
    return file_exists("/proc/$pid");
}

function getRunningTime($pid)
{
    if (!file_exists("/proc/$pid")) {
        return null;
    }
    $startTime = time() - intval(filemtime("/proc/$pid"));
    return $startTime > 0 ? $startTime : null;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FFmpeg Stream Manager</title>
    <style>
        #video-from-uploaded, #audio-from-uploaded {
            display: none;
        }
        <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background-color: #f4f4f4; }
        button { padding: 5px 10px; margin: 0; cursor: pointer; }
        #file-from-uploaded {
            display: none;
        }
    </style>
    </style>
</head>
<body>
    <h1>FFmpeg Stream Manager</h1>
    <form id="ffmpeg-form" method="POST" enctype="multipart/form-data">
        <h3>Select Video File Source:</h3>
        <label>
            <input type="radio" name="video_source" value="computer" checked>
            Stream Video from Computer
        </label>
        <label>
            <input type="radio" name="video_source" value="uploaded">
            Stream Video from Uploaded Files
        </label>

        <!-- For video file from the computer -->
        <div id="video-from-computer">
            <label for="input_video">Select Video File:</label>
            <input type="file" name="input_video" id="input_video" accept="video/*">
        </div>

        <!-- For video file from previously uploaded files -->
        <div id="video-from-uploaded">
            <label for="uploaded_video">Choose from Uploaded Videos:</label>
            <select name="uploaded_video" id="uploaded_video">
                <!-- Populated dynamically -->
            </select>
        </div>

        <h3>Select Audio File Source:</h3>
        <label>
            <input type="radio" name="audio_source" value="computer" checked>
            Upload Audio from Computer
        </label>
        <label>
            <input type="radio" name="audio_source" value="uploaded">
            Select from Uploaded Audio Files
        </label>

        <!-- For audio file from the computer -->
        <div id="audio-from-computer">
            <label for="input_audio">Select Audio Files:</label>
            <input type="file" name="audio_files[]" id="input_audio" multiple accept="audio/*">
        </div>

        <!-- For audio files from previously uploaded files -->
        <div id="audio-from-uploaded">
            <label for="uploaded_audio">Choose from Uploaded Audio Files:</label>
            <select name="uploaded_audio[]" id="uploaded_audio" multiple>
                <!-- Populated dynamically -->
            </select>
        </div>

        <h3>Stream Key:</h3>
        <input type="text" name="stream_key" placeholder="Enter your stream key" required>

        <button type="submit" name="action" value="start">Start Live Stream</button>
    </form>

    <h3>Processes</h3>
    <table border="1">
    <thead>
        <tr>
            <th>Video File</th>
            <th>Audio Files</th>
            <th>Stream Key</th>
            <th>Status</th>
            <th>PID</th>
            <th>Running Time</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php
            for ($i = 0; $i < count($processes); $i++):
                $pid = $processes[$i]['pid'];
                $isRunning = isProcessRunning($pid);
                $runningTime = $isRunning ? getRunningTime($pid) : null;
            ?>
            <tr>
                <td><?= htmlspecialchars(basename($processes[$i]['file'])) ?></td>
                <td>
                    <?php
                    if (!empty($processes[$i]['audio_files'])):
                        foreach ($processes[$i]['audio_files'] as $audioFile):
                            echo htmlspecialchars(basename($audioFile)) . "<br>";
                        endforeach;
                    else:
                        echo "N/A";
                    endif;
                    ?>
                </td>
                <td><?= htmlspecialchars($processes[$i]['stream_key']) ?></td>
                <td><?= $isRunning ? "Running" : "Stopped" ?></td>
                <td><?= htmlspecialchars($pid) ?></td>
                <td><?= $isRunning ? gmdate("H:i:s", $runningTime) : "N/A" ?></td>
                <td>
                     <form method="POST" style="display: inline;">
                            <input type="hidden" name="pid" value="<?= htmlspecialchars($pid) ?>">
                            <button type="submit" name="action" value="stop" <?= !$isRunning ? "disabled" : "" ?>>Stop</button>
                        </form>
                </td>
            </tr>
        <?php endfor; ?>
    </tbody>

</table>

    <script>
        // Toggle video file selection
        document.querySelectorAll('input[name="video_source"]').forEach(radio => {
            radio.addEventListener('change', () => {
                if (radio.value === 'computer') {
                    document.getElementById('video-from-computer').style.display = 'block';
                    document.getElementById('video-from-uploaded').style.display = 'none';
                } else {
                    document.getElementById('video-from-computer').style.display = 'none';
                    document.getElementById('video-from-uploaded').style.display = 'block';
                }
            });
        });

        // Toggle audio file selection
        document.querySelectorAll('input[name="audio_source"]').forEach(radio => {
            radio.addEventListener('change', () => {
                if (radio.value === 'computer') {
                    document.getElementById('audio-from-computer').style.display = 'block';
                    document.getElementById('audio-from-uploaded').style.display = 'none';
                } else {
                    document.getElementById('audio-from-computer').style.display = 'none';
                    document.getElementById('audio-from-uploaded').style.display = 'block';
                }
            });
        });

        // Fetch uploaded files dynamically
        document.addEventListener("DOMContentLoaded", () => {
            fetch("ffmpeg3.php?action=get_uploaded_files&type=video")
                .then(response => response.json())
                .then(files => {
                    const select = document.getElementById('uploaded_video');
                    select.innerHTML = ""; // Clear previous options
                    files.forEach(file => {
                        const option = document.createElement('option');
                        option.value = file;
                        option.textContent = file;
                        select.appendChild(option);
                    });
                });

            fetch("index.php?action=get_uploaded_files&type=audio")
                .then(response => response.json())
                .then(files => {
                    const select = document.getElementById('uploaded_audio');
                    select.innerHTML = ""; // Clear previous options
                    files.forEach(file => {
                        const option = document.createElement('option');
                        option.value = file;
                        option.textContent = file;
                        select.appendChild(option);
                    });
                });

            // Fetch processes dynamically
            setInterval(fetchProcesses, 5000);
        });

        // Fetch running processes
        function fetchProcesses() {
            fetch("index.php?action=get_processes")
                .then(response => response.json())
                .then(processes => {
                    const table = document.getElementById('process-table');
                    table.innerHTML = ""; // Clear previous rows
                    processes.forEach(process => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td>${process.video}</td>
                            <td>${process.audio_files.join(", ")}</td>
                            <td>${process.stream_key}</td>
                            <td>${process.status}</td>
                            <td>${process.pid}</td>
                            <td>
                                <button onclick="stopProcess(${process.pid})">Stop</button>
                            </td>
                        `;
                        table.appendChild(row);
                    });
                });
        }

        // Stop a process
        function stopProcess(pid) {
            fetch(`index.php?action=stop&pid=${pid}`)
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        alert("Process stopped successfully");
                    } else {
                        alert("Failed to stop process");
                    }
                    fetchProcesses();
                });
        }
    </script>
</body>
</html>
