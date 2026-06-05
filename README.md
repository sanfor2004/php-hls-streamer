![Repo Poster](Repo.png)

# StreamingScript

StreamingScript is a highly robust, zero-configuration Vanilla PHP + FFmpeg video hosting, transcoding, and streaming platform featuring multi-resolution HLS encoding, SQLite data persistence, and VAST/VPAID offset-based ad integration.

## Features

- **Chunked Video Ingestion:** Vanilla JS drag-and-drop upload interface slicing massive videos into 5MB chunks to bypass server file boundaries and timeouts.
- **FFmpeg Transcoding Ladder:** Automated background CLI worker generating multi-resolution HLS targets (`1080p`, `720p`, `540p`, `480p`, `360p`) and standard adaptive master playlists (`.m3u8` + `.ts`).
- **VAST/VPAID Ad Manager:** Dashboard campaign scheduler supporting pre/mid/post-roll triggers based on custom offsets (seconds, percentage, timestamps).
- **Video.js Adaptive Player:** Quality gear selectors and dynamic ad scheduling via the Google IMA SDK.
- **Zero-Configuration SQLite Backend:** Self-healing SQLite connection that auto-creates all required tables on startup.

## Project Structure

```text
stream.php          Unified administration dashboard & ingestion/settings APIs
transcode.php       Asynchronous FFmpeg transcode worker (CLI background process)
embed.php           Quality selector HLS player & Google IMA offset scheduler
database.sqlite     Self-contained SQLite database (auto-initialized on first boot)
Input/              Stitched temporary files & active upload chunk parts
Output/             Completed streams directories (playlists & video segments)
```

## Requirements

- PHP 8.0+ (with `pdo_sqlite` extension enabled)
- FFmpeg in system PATH
- FFprobe in system PATH

Verify your local system setup:

```powershell
php -v
ffmpeg -version
ffprobe -version
```

## Run Locally

From the project directory:

```powershell
php -S 127.0.0.1:8800
```

Open in browser to upload and manage campaigns:

```text
http://127.0.0.1:8800/stream.php
```

## API Endpoints

### 1. Ingest Video Chunk
- **Method:** `POST`
- **URL:** `/stream.php?action=upload_chunk`
- **Fields:** `file_id`, `chunk_index`, `total_chunks`, `filename`, `resolutions`, `video_chunk`
- **Response:** JSON confirming progress or stitch completion.

### 2. Stream Ingestion Catalog
- **Method:** `GET`
- **URL:** `/stream.php?action=list`
- **Response:** Detailed JSON listing of all registered stream items and transcode states.

### 3. Add VAST Ad Campaign
- **Method:** `POST`
- **URL:** `/stream.php?action=add_ad`
- **Fields:** `name`, `vast_url`, `offset_type`, `offset_value`

### 4. Player Embed Page
- **Method:** `GET`
- **URL:** `/embed.php?id={stream_id}`
- **Usage Example:**
  ```html
  <iframe
    src="http://127.0.0.1:8800/embed.php?id=YOUR_STREAM_ID"
    width="960"
    height="540"
    allowfullscreen
  ></iframe>
  ```

## Ingestion & Transcoding Flow

1. Browser slices video file into 5MB chunks, uploading sequentially via AJAX.
2. Server reassembles chunks in `Input/` and creates a stream record with state `pending` in `database.sqlite`.
3. Server spawns `transcode.php` asynchronously via a background CLI shell call, immediately returning a success status to the browser.
4. FFprobe reads codecs and file metadata, updating the SQLite row.
5. FFmpeg executes multi-resolution conversions, writing segments into `Output/{stream_id}/{resolution}/`.
6. A master HLS playlist (`master.m3u8`) is compiled, and the database status transitions to `ready`.
7. **The original source video file is automatically deleted from the `Input/` directory to conserve storage.**
